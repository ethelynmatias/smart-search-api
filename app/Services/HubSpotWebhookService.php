<?php

namespace App\Services;

use App\Services\SmartSearch\AmlService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotWebhookService
{
    /**
     * Contact fields the AML search cannot run without.
     */
    protected const AML_REQUIRED_FIELDS = ['title', 'first_name', 'last_name', 'address1', 'city', 'postcode'];

    public function __construct(
        protected LogService $logService,
        protected AmlService $amlService,
    ) {}

    /**
     * Verify the X-HubSpot-Signature-v3 header.
     *
     * @see https://developers.hubspot.com/docs/api/webhooks/validating-requests
     */
    public function hasValidSignature(Request $request): bool
    {
        $secret = config('services.hubspot.client_secret');

        if (blank($secret)) {
            Log::warning('HubSpot webhook received but services.hubspot.client_secret is not set.');

            return false;
        }

        $signature = $request->header('X-HubSpot-Signature-v3');
        $timestamp = $request->header('X-HubSpot-Request-Timestamp');

        if (blank($signature) || blank($timestamp)) {
            return false;
        }

        // Reject requests older than 5 minutes to prevent replay attacks
        if (abs(now()->getTimestampMs() - (int) $timestamp) > 300_000) {
            return false;
        }

        $source = $request->method().$request->fullUrl().$request->getContent().$timestamp;
        $expected = base64_encode(hash_hmac('sha256', $source, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Dispatch a single webhook event by subscription type.
     */
    public function handleEvent(array $event): void
    {
        $type = $event['subscriptionType'] ?? 'unknown';

        $this->logService->webhook("HubSpot: {$type}", $event);

        match ($type) {
            'deal.propertyChange' => $this->handleDealPropertyChange($event),
            default => Log::debug('Unhandled HubSpot webhook event', ['type' => $type]),
        };
    }

    /**
     * Handle a deal property change. When a deal is closed (won or lost),
     * fetch its associated contacts and log their fields.
     */
    protected function handleDealPropertyChange(array $event): void
    {
        $value = $event['propertyValue'] ?? null;

        if (! in_array($value, ['closedwon', 'closedlost'], true)) {
            return;
        }

        $dealId = $event['objectId'] ?? null;

        if (blank($dealId)) {
            return;
        }

        $deal = $this->fetchDeal((string) $dealId);
        $contacts = $this->fetchDealContacts((string) $dealId);

        $log = $this->logService->webhook("HubSpot: deal {$value} contacts", [
            'dealId' => $dealId,
            'propertyName' => $event['propertyName'] ?? null,
            'propertyValue' => $value,
            'deal' => $deal,
            'contacts' => $contacts,
        ]);

        if (blank($contacts)) {
            return;
        }

        // Run the AML search per contact and fold the results back into the
        // same log record, so the whole deal lives under one id.
        $log->update([
            'payload' => [...$log->payload, 'aml' => $this->runAmlSearches($contacts)],
        ]);
    }

    /**
     * Run a SmartSearch AML search for each contact on the deal.
     *
     * Never throws: a contact that cannot be searched is recorded alongside
     * the ones that could, so one bad contact does not lose the rest.
     */
    protected function runAmlSearches(array $contacts): array
    {
        $results = [];

        foreach ($contacts as $contact) {
            $properties = $contact['properties'] ?? [];

            $data = [
                'title' => $properties['salutation'] ?? null,
                'first_name' => $properties['firstname'] ?? null,
                'last_name' => $properties['lastname'] ?? null,
                'address1' => $properties['address'] ?? null,
                'city' => $properties['city'] ?? null,
                'postcode' => $properties['zip'] ?? null,
            ];

            $missing = array_values(array_filter(
                self::AML_REQUIRED_FIELDS,
                fn (string $field) => blank($data[$field] ?? null),
            ));

            if (filled($missing)) {
                $results[] = [
                    'contactId' => $contact['id'] ?? null,
                    'skipped' => 'missing required contact fields',
                    'missing' => $missing,
                ];

                continue;
            }

            try {
                $results[] = [
                    'contactId' => $contact['id'] ?? null,
                    'result' => $this->amlService->search($data),
                ];
            } catch (SmartSearchException $e) {
                Log::warning('SmartSearch AML search failed for HubSpot contact.', [
                    'contactId' => $contact['id'] ?? null,
                    'status' => $e->status,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'contactId' => $contact['id'] ?? null,
                    'error' => $e->getMessage(),
                    'status' => $e->status,
                    'errors' => $e->errors,
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch a deal's properties from the HubSpot API.
     */
    protected function fetchDeal(string $dealId): array
    {
        $token = config('services.hubspot.access_token');

        if (blank($token)) {
            Log::warning('HubSpot access token is not set; cannot fetch deal.', ['dealId' => $dealId]);

            return [];
        }

        $response = $this->client($token)->get("/crm/v3/objects/deals/{$dealId}", [
            'properties' => 'dealname,amount,dealstage,pipeline,closedate,hubspot_owner_id,dealtype',
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot deal.', [
                'dealId' => $dealId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return [
            'id' => $response->json('id'),
            'properties' => $response->json('properties', []),
        ];
    }

    /**
     * Fetch the contacts associated with a deal from the HubSpot API.
     */
    protected function fetchDealContacts(string $dealId): array
    {
        $token = config('services.hubspot.access_token');

        if (blank($token)) {
            Log::warning('HubSpot access token is not set; cannot fetch deal contacts.', ['dealId' => $dealId]);

            return [];
        }

        $client = $this->client($token);

        $associations = $client->get("/crm/v4/objects/deals/{$dealId}/associations/contacts");

        if ($associations->failed()) {
            Log::warning('Failed to fetch HubSpot deal contact associations.', [
                'dealId' => $dealId,
                'status' => $associations->status(),
                'body' => $associations->json(),
            ]);

            return [];
        }

        $contactIds = collect($associations->json('results', []))
            ->pluck('toObjectId')
            ->filter()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        $response = $client->post('/crm/v3/objects/contacts/batch/read', [
            // salutation/address/city/zip feed the AML search.
            'properties' => ['firstname', 'lastname', 'email', 'phone', 'company', 'lifecyclestage', 'salutation', 'address', 'city', 'zip', 'state', 'country'],
            'inputs' => $contactIds->map(fn ($id) => ['id' => (string) $id])->all(),
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot contacts for deal.', [
                'dealId' => $dealId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return collect($response->json('results', []))
            ->map(fn (array $contact) => [
                'id' => $contact['id'] ?? null,
                'properties' => $contact['properties'] ?? [],
            ])
            ->all();
    }

    /**
     * Build an authenticated HubSpot API client.
     */
    protected function client(string $token): PendingRequest
    {
        return Http::withToken($token)->baseUrl('https://api.hubapi.com');
    }
}
