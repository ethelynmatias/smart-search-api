<?php

namespace App\Services;

use App\Services\SmartSearch\AmlService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\SmartDocService;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HubSpotWebhookService
{
    /**
     * Contact fields the AML search cannot run without.
     */
    protected const AML_REQUIRED_FIELDS = ['title', 'first_name', 'last_name', 'address1', 'city', 'postcode'];

    /**
     * Contact fields the SmartDoc verification cannot be created without.
     */
    protected const SMARTDOC_REQUIRED_FIELDS = ['first_name', 'last_name', 'building', 'town', 'postcode', 'date_of_birth', 'sex'];

    public function __construct(
        protected LogService $logService,
        protected AmlService $amlService,
        protected SmartDocService $smartDocService,
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
        $company = $this->fetchDealCompany((string) $dealId);

        $log = $this->logService->webhook("HubSpot: deal {$value} contacts", [
            'dealId' => $dealId,
            'propertyName' => $event['propertyName'] ?? null,
            'propertyValue' => $value,
            'deal' => $deal,
            'contacts' => $contacts,
            'company' => $company,
        ]);

        // Contacts are the preferred AML subjects; with none on the deal we
        // fall back to the company owner, using the company's own address.
        $aml = filled($contacts)
            ? $this->runAmlSearches($contacts)
            : $this->runCompanyOwnerAmlSearch($company);

        if (filled($aml)) {
            // Fold the results back into the same log record, so the whole deal
            /*$log->update([
                'payload' => [...$log->payload, 'aml' => $aml],
            ]);*/
            $this->logService->webhook("HubSpot: deal {$value} aml", [
                'dealId' => $dealId,
                'propertyValue' => $value,
                'aml' => $aml,
            ]);

        }

        // SmartDoc runs off the same subjects. It lands on its own log line so
        // the two searches read separately, sharing the log group id.
        $smartDoc = filled($contacts)
            ? $this->runSmartDocSearches($contacts)
            : $this->runCompanyOwnerSmartDocSearch($company);

        if (blank($smartDoc)) {
            return;
        }

        $this->logService->webhook("HubSpot: deal {$value} smartdoc", [
            'dealId' => $dealId,
            'propertyValue' => $value,
            'smartdoc' => $smartDoc,
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

            $results[] = $this->runAmlSearch(
                [
                    'title' => $properties['salutation'] ?? null,
                    'first_name' => $properties['firstname'] ?? null,
                    'last_name' => $properties['lastname'] ?? null,
                    'address1' => $properties['address'] ?? null,
                    'city' => $properties['city'] ?? null,
                    'postcode' => $properties['zip'] ?? null,
                ],
                ['contactId' => $contact['id'] ?? null],
                self::AML_REQUIRED_FIELDS,
            );
        }

        return $results;
    }

    /**
     * Run a single AML search for the company owner, for deals that have no
     * associated contacts. The owner supplies the name and the company the
     * address; owners carry no salutation, so title is not required here.
     */
    protected function runCompanyOwnerAmlSearch(array $company): array
    {
        $owner = $company['owner'] ?? [];

        if (blank($owner)) {
            return [];
        }

        $properties = $company['properties'] ?? [];

        $result = $this->runAmlSearch(
            [
                'title' => null,
                'first_name' => $owner['firstName'] ?? null,
                'last_name' => $owner['lastName'] ?? null,
                'address1' => $properties['address'] ?? null,
                'city' => $properties['city'] ?? null,
                'postcode' => $properties['zip'] ?? null,
            ],
            [
                'source' => 'company owner',
                'companyId' => $company['id'] ?? null,
                'ownerId' => $owner['id'] ?? null,
            ],
            array_values(array_diff(self::AML_REQUIRED_FIELDS, ['title'])),
        );

        return [$result];
    }

    /**
     * Create a SmartDoc verification for each contact on the deal.
     */
    protected function runSmartDocSearches(array $contacts): array
    {
        $results = [];

        foreach ($contacts as $contact) {
            $results[] = $this->runSmartDocSearch(
                $this->smartDocData($contact['properties'] ?? []),
                ['contactId' => $contact['id'] ?? null],
            );
        }

        return $results;
    }

    /**
     * Create a SmartDoc verification for the company owner, for deals that
     * have no associated contacts.
     */
    protected function runCompanyOwnerSmartDocSearch(array $company): array
    {
        $owner = $company['owner'] ?? [];

        if (blank($owner)) {
            return [];
        }

        $data = $this->smartDocData($company['properties'] ?? []);

        // The owner supplies the name, the company the address.
        $data['first_name'] = $owner['firstName'] ?? null;
        $data['last_name'] = $owner['lastName'] ?? null;

        return [
            $this->runSmartDocSearch($data, [
                'source' => 'company owner',
                'companyId' => $company['id'] ?? null,
                'ownerId' => $owner['id'] ?? null,
            ]),
        ];
    }

    /**
     * Map HubSpot properties onto the SmartDoc payload fields.
     *
     * Every key SmartDocService reads is present, so a property HubSpot does
     * not hold is sent as null rather than raising an undefined key warning.
     */
    protected function smartDocData(array $properties): array
    {
        return [
            'title' => $properties['salutation'] ?? null,
            'first_name' => $properties['firstname'] ?? null,
            'middle_name' => null,
            'last_name' => $properties['lastname'] ?? null,
            'date_of_birth' => $this->normaliseDate($properties['date_of_birth'] ?? null),
            'sex' => $this->normaliseSex($properties['gender'] ?? null),
            'building' => $properties['address'] ?? null,
            'street_1' => $properties['address'] ?? null,
            'town' => $properties['city'] ?? null,
            'region' => $properties['state'] ?? null,
            'postcode' => $properties['zip'] ?? null,
            'country' => $properties['country'] ?? 'GBR',
        ];
    }

    /**
     * Normalise a HubSpot date property to the Y-m-d SmartDoc expects.
     *
     * Date properties come back as either an ISO date or epoch milliseconds
     * depending on how the property was written.
     */
    protected function normaliseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestampMs((int) $value)->format('Y-m-d')
                : Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalise a HubSpot gender property to the SmartDoc sex values.
     *
     * Anything that is not recognisably male or female is dropped rather
     * than guessed at, so the search is skipped instead of sent wrong.
     */
    protected function normaliseSex(mixed $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
    }

    /**
     * Run one SmartDoc creation and describe the outcome.
     *
     * Never throws, for the same reason as the AML searches.
     */
    protected function runSmartDocSearch(array $data, array $meta): array
    {
        $missing = array_values(array_filter(
            self::SMARTDOC_REQUIRED_FIELDS,
            fn (string $field) => blank($data[$field] ?? null),
        ));

        if (filled($missing)) {
            return [...$meta, 'skipped' => 'missing required smartdoc fields', 'missing' => $missing];
        }

        try {
            return [...$meta, 'result' => $this->smartDocService->create($data)];
        } catch (SmartSearchException $e) {
            Log::warning('SmartDoc creation failed for HubSpot subject.', [
                ...$meta,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return [...$meta, 'error' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors];
        }
    }

    /**
     * Run one SmartSearch AML search and describe the outcome.
     *
     * Never throws: a subject that cannot be searched is recorded alongside
     * the ones that could, so one bad subject does not lose the rest.
     *
     * @param  array  $meta  identifying fields merged into the result
     * @param  array  $required  fields that must be present to search
     */
    protected function runAmlSearch(array $data, array $meta, array $required): array
    {
        $missing = array_values(array_filter(
            $required,
            fn (string $field) => blank($data[$field] ?? null),
        ));

        if (filled($missing)) {
            return [...$meta, 'skipped' => 'missing required contact fields', 'missing' => $missing];
        }

        try {
            return [...$meta, 'result' => $this->amlService->search($data)];
        } catch (SmartSearchException $e) {
            Log::warning('SmartSearch AML search failed for HubSpot subject.', [
                ...$meta,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return [...$meta, 'error' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors];
        }
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
            // salutation/address/city/zip feed the AML search;
            // date_of_birth/gender feed the SmartDoc verification.
            'properties' => ['firstname', 'lastname', 'email', 'phone', 'company', 'lifecyclestage', 'salutation', 'address', 'city', 'zip', 'state', 'country', 'date_of_birth', 'gender'],
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
     * Fetch the company associated with a deal, along with its owner.
     *
     * A deal can carry several companies; HubSpot treats the first association
     * as the primary one, which is the one we record.
     */
    protected function fetchDealCompany(string $dealId): array
    {
        $token = config('services.hubspot.access_token');

        if (blank($token)) {
            Log::warning('HubSpot access token is not set; cannot fetch deal company.', ['dealId' => $dealId]);

            return [];
        }

        $client = $this->client($token);

        $associations = $client->get("/crm/v4/objects/deals/{$dealId}/associations/companies");

        if ($associations->failed()) {
            Log::warning('Failed to fetch HubSpot deal company associations.', [
                'dealId' => $dealId,
                'status' => $associations->status(),
                'body' => $associations->json(),
            ]);

            return [];
        }

        $companyId = collect($associations->json('results', []))
            ->pluck('toObjectId')
            ->filter()
            ->first();

        if (blank($companyId)) {
            return [];
        }

        $response = $client->get("/crm/v3/objects/companies/{$companyId}", [
            'properties' => 'name,domain,industry,phone,address,city,state,zip,country,hubspot_owner_id',
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot company for deal.', [
                'dealId' => $dealId,
                'companyId' => $companyId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $properties = $response->json('properties', []);

        return [
            'id' => $response->json('id'),
            'properties' => $properties,
            'owner' => $this->fetchOwner($properties['hubspot_owner_id'] ?? null),
        ];
    }

    /**
     * Fetch a HubSpot owner (user) record by id.
     */
    protected function fetchOwner(?string $ownerId): array
    {
        if (blank($ownerId)) {
            return [];
        }

        $token = config('services.hubspot.access_token');

        if (blank($token)) {
            return [];
        }

        $response = $this->client($token)->get("/crm/v3/owners/{$ownerId}");

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot owner.', [
                'ownerId' => $ownerId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return [
            'id' => $response->json('id'),
            'email' => $response->json('email'),
            'firstName' => $response->json('firstName'),
            'lastName' => $response->json('lastName'),
            'userId' => $response->json('userId'),
        ];
    }

    /**
     * Build an authenticated HubSpot API client.
     */
    protected function client(string $token): PendingRequest
    {
        return Http::withToken($token)->baseUrl('https://api.hubapi.com');
    }
}
