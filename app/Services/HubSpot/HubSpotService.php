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
     * Write the SmartDoc search status back onto the deal.
     */
    public function updateSmartDocStatus(string $dealId, string $status): array
    {
        return $this->updateDealProperties($dealId, ['smartdoc_status' => $status]);
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
