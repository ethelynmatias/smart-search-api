<?php

namespace App\Services\HubSpot;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class HubSpotService
{
    public function __construct(
        protected HubSpotAuthService $auth,
    ) {}

    /**
     * Write the SmartDoc search id back onto the deal.
     */
    public function updateSmartDocSsid(string $dealId, string $smartDocSsid): array
    {
        return $this->updateDealProperties($dealId, ['smartdoc_ssid' => $smartDocSsid]);
    }

    /**
     * Write the SmartDoc search id onto the contact it was created for.
     *
     * One contact is one subject is one search, so this is the id of that
     * contact's own search rather than the deal's list of all of them.
     */
    public function updateContactSmartDocSsid(string $contactId, string $ssid): array
    {
        return $this->updateContactProperties($contactId, ['smartdoc_ssid' => $ssid]);
    }

    /**
     * Write the SmartDoc search response onto the contact it belongs to.
     *
     * Takes the response either as the decoded array it arrives as or as a
     * string already prepared by the caller, so the encoding is decided in one
     * place rather than at each call site.
     */
    public function updateContactSmartDocResponse(string $contactId, array|string|null $response): array
    {
        return $this->updateContactProperties($contactId, [
            'smartdoc_response' => is_array($response) ? json_encode($response) : (string) $response,
        ]);
    }

    /**
     * Write the AML search id onto the contact it was created for.
     */
    public function updateContactAmlSsid(string $contactId, string $ssid): array
    {
        return $this->updateContactProperties($contactId, ['aml_ssid' => $ssid]);
    }

    /**
     * Write the AML search response onto the contact it belongs to.
     *
     * Takes the response either as the decoded array it arrives as or as a
     * string already prepared by the caller, so the encoding is decided in one
     * place rather than at each call site.
     */
    public function updateContactAmlResponse(string $contactId, array|string|null $response): array
    {
        return $this->updateContactProperties($contactId, [
            'aml_response' => is_array($response) ? json_encode($response) : (string) $response,
        ]);
    }

    /**
     * Patch properties onto a contact.
     *
     * Never throws, for the same reason the deal write does not: the search is
     * already held on the webhook detail, so a write-back that fails costs the
     * contact properties, not the record of the search.
     */
    protected function updateContactProperties(string $contactId, array $properties): array
    {
        $client = $this->auth->client('update contact properties');

        if (blank($client)) {
            return [];
        }

        $response = $client->patch("/crm/v3/objects/contacts/{$contactId}", [
            'properties' => $properties,
        ]);

        if ($response->failed()) {
            Log::warning('Failed to write properties onto the HubSpot contact.', [
                'contactId' => $contactId,
                'properties' => $properties,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return $response->json();
    }

    /**
     * Record a SmartDoc search status on the deal, keyed by ssid:
     */
    public function updateSmartDocStatus(string $dealId, string $ssid, string $status, ?Carbon $date = null, ?string $contactId = null): array
    {
        $searches = $this->smartDocStatuses($dealId);

        $searches[$ssid] = [
            'status' => $status,
            // An ssid we have seen before keeps the date it was first written.
            'date_created' => data_get($searches, [$ssid, 'date_created']) ?? $this->dateProperty($date),
            'date_updated' => $this->dateProperty($date),
            // Kept from the entry already on the deal when the caller has no
            // contact to hand, so a status update never blanks it.
            'hubspot_contact_id' => $contactId ?? data_get($searches, [$ssid, 'hubspot_contact_id']),
        ];

        return $this->updateDealProperties($dealId, [
            'smartdoc_status' => json_encode($searches),
        ]);
    }

    /**
     * The SmartDoc statuses already recorded on a deal, keyed by ssid.
     *
     * @return array<string, array{status: string, date_created: string, date_updated: string, hubspot_contact_id: ?string}>
     */
    protected function smartDocStatuses(string $dealId): array
    {
        $value = $this->dealProperty($dealId, 'smartdoc_status');

        if (blank($value)) {
            return [];
        }

        $searches = json_decode($value, true);

        if (! is_array($searches)) {
            Log::warning('The smartdoc status property on the HubSpot deal is not the JSON we write.', [
                'dealId' => $dealId,
                'smartdocStatus' => $value,
            ]);

            return [];
        }

        return $searches;
    }

    /**
     * Add the UK individual AML search ids to the ones already on the deal.
     */
    public function updateSmartSearchUkIndividualSsid(string $dealId, string $ssid): array
    {
        $existing = $this->dealProperty($dealId, 'smartsearch_uk_individual_ssid');

        $ssids = collect(explode(',', (string) $existing))
            ->concat(explode(',', $ssid))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->values();

        return $this->updateDealProperties($dealId, [
            'smartsearch_uk_individual_ssid' => $ssids->implode(','),
        ]);
    }

    /**
     * Read one property off a deal.
     */
    protected function dealProperty(string $dealId, string $property): ?string
    {
        $client = $this->auth->client("fetch deal {$property}");

        if (blank($client)) {
            return null;
        }

        $response = $client->get("/crm/v3/objects/deals/{$dealId}", [
            'properties' => $property,
        ]);

        if ($response->failed()) {
            Log::warning('Failed to read a property off the HubSpot deal.', [
                'dealId' => $dealId,
                'property' => $property,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        return $response->json("properties.{$property}");
    }

    /**
     * Stamp the date the SmartDoc search was requested onto the deal.
     */
    public function updateSmartDocRequestDate(string $dealId, ?Carbon $date = null): array
    {
        return $this->updateDealProperties($dealId, [
            'smartdoc_request_date' => $this->dateProperty($date),
        ]);
    }

    /**
     * Stamp the date the UK individual AML search was requested onto the deal,
     * without disturbing a date already on it.
     */
    public function updateUkIndividualRequestDate(string $dealId, ?Carbon $date = null): array
    {
        $existing = $this->dealProperty($dealId, 'uk_individual_request_date');

        if (filled($existing)) {
            Log::debug('HubSpot deal already holds a uk individual request date; keeping it.', [
                'dealId' => $dealId,
                'ukIndividualRequestDate' => $existing,
            ]);

            return [];
        }

        return $this->updateDealProperties($dealId, [
            'uk_individual_request_date' => $this->dateProperty($date),
        ]);
    }

    /**
     * HubSpot date properties are whole days held at midnight UTC, so anything
     * with a time on it is rejected unless the time is stripped first.
     */
    protected function dateProperty(?Carbon $date): string
    {
        return ($date ?? now())->utc()->format('Y-m-d');
    }

    /**
     * Patch properties onto a deal.
     *
     * Never throws: the search and its status are already held on the webhook
     * detail, so a write-back that fails costs the deal properties, not the
     * record of the search.
     */
    protected function updateDealProperties(string $dealId, array $properties): array
    {
        $client = $this->auth->client('update deal properties');

        if (blank($client)) {
            return [];
        }

        $response = $client->patch("/crm/v3/objects/deals/{$dealId}", [
            'properties' => $properties,
        ]);

        if ($response->failed()) {
            Log::warning('Failed to write properties onto the HubSpot deal.', [
                'dealId' => $dealId,
                'properties' => $properties,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return $response->json();
    }
}
