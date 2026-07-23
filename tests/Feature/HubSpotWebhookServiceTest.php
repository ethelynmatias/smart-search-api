<?php

namespace Tests\Feature;

use App\Services\HubSpotWebhookService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class HubSpotWebhookServiceTest extends TestCase
{
    /**
     * Manually change this to a real deal ID when running against the live API.
     */
    protected string $dealId = '58330565175';

    /**
     * Call the protected fetchDealContacts() with a deal ID.
     */
    protected function fetchDealContacts(string $dealId): array
    {
        $service = app(HubSpotWebhookService::class);

        $method = new ReflectionMethod($service, 'fetchDealContacts');

        return $method->invoke($service, $dealId);
    }

    /**
     * Fetches the real contacts for $dealId from the live HubSpot API
     * and dumps the list. Requires HUBSPOT_ACCESS_TOKEN in .env.
     */
    public function test_fetch_contacts_from_deal(): void
    {
        if (blank(config('services.hubspot.access_token'))) {
            $this->markTestSkipped('Set HUBSPOT_ACCESS_TOKEN to run this test.');
        }

        $contacts = $this->fetchDealContacts($this->dealId);

        dump($contacts);

        $this->assertIsArray($contacts);
    }
}
