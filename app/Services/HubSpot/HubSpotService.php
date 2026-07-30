<?php

namespace App\Services\HubSpot;

use Illuminate\Support\Facades\Log;

class HubSpotService
{
    public function __construct(
        protected HubSpotAuthService $auth,
    ) {}

    /**
     * Write the SmartDoc search id back onto the deal.
     *
     * Never throws: the ssid is already held on the webhook detail, so a
     * write-back that fails costs the property on the deal, not the search.
     */
    public function updateSmartDocSsid(string $dealId, string $smartDocSsid): array
    {
        $client = $this->auth->client('update deal smartdoc ssid');

        if (blank($client)) {
            return [];
        }

        $response = $client->patch("/crm/v3/objects/deals/{$dealId}", [
            'properties' => [
                'smartdoc_ssid' => $smartDocSsid,
            ],
        ]);

        if ($response->failed()) {
            Log::warning('Failed to write the SmartDoc ssid onto the HubSpot deal.', [
                'dealId' => $dealId,
                'ssid' => $smartDocSsid,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return $response->json();
    }
}
