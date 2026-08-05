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
     * Record a SmartDoc search status on the deal, keyed by ssid:
     *
     *     {"<ssid>": {"status": "pending", "date_created": "2026-08-05", "date_updated": "2026-08-05", "hubspot_contact_id": "<id>"}}
     *
     * The property is appended to rather than overwritten, so a deal searched
     * for several subjects — or searched again — keeps a status per search. An
     * ssid already on the deal keeps its date_created and only has its status
     * and date_updated moved on.
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
        $client = $this->auth->client('fetch deal smartdoc statuses');

        if (blank($client)) {
            return [];
        }

        $response = $client->get("/crm/v3/objects/deals/{$dealId}", [
            'properties' => 'smartdoc_status',
        ]);

        if ($response->failed()) {
            Log::warning('Failed to read the smartdoc statuses off the HubSpot deal.', [
                'dealId' => $dealId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $value = $response->json('properties.smartdoc_status');

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
     * Write the UK individual AML search id back onto the deal.
     */
    public function updateSmartSearchUkIndividualSsid(string $dealId, string $ssid): array
    {
        return $this->updateDealProperties($dealId, ['smartsearch_uk_individual_ssid' => $ssid]);
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
     * Stamp the date the UK individual AML search was requested onto the deal.
     */
    public function updateUkIndividualRequestDate(string $dealId, ?Carbon $date = null): array
    {
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
