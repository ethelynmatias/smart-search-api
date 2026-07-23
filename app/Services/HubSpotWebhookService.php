<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotWebhookService
{
    public function __construct(
        protected LogService $logService,
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

        $this->logService->webhook("HubSpot: deal {$value} contacts", [
            'dealId' => $dealId,
            'propertyName' => $event['propertyName'] ?? null,
            'propertyValue' => $value,
            'deal' => $deal,
            'contacts' => $contacts,
        ]);
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
            'properties' => ['firstname', 'lastname', 'email', 'phone', 'company', 'lifecyclestage'],
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
