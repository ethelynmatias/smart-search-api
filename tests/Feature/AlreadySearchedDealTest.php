<?php

namespace Tests\Feature;

use App\Models\Log as LogModel;
use App\Services\HubSpot\HubSpotWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class AlreadySearchedDealTest extends TestCase
{
    use RefreshDatabase;

    protected string $dealId = '58338329725';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hubspot.access_token' => 'test-token']);
    }

    /**
     * Fake HubSpot with a deal carrying the given properties, and answer every
     * other call with an empty 200.
     */
    protected function fakeDeal(array $properties): void
    {
        Http::fake([
            "*/crm/v3/objects/deals/{$this->dealId}?*" => Http::response([
                'id' => $this->dealId,
                'properties' => ['dealname' => 'cdeal', 'dealstage' => 'closedlost', ...$properties],
            ]),
            '*' => Http::response([]),
        ]);
    }

    protected function handle(string $property = 'ss_smartdoc'): void
    {
        $service = app(HubSpotWebhookService::class);

        (new ReflectionMethod($service, 'handleDealPropertyChange'))->invoke($service, [
            'subscriptionType' => 'deal.propertyChange',
            'objectId' => (int) $this->dealId,
            'propertyName' => $property,
            'propertyValue' => 'true',
        ]);
    }

    protected function assertProcessed(string $property, int $times): void
    {
        $this->assertSame($times, LogModel::where('message', "HubSpot: deal {$property} contacts")->count());
    }

    public function test_a_deal_with_no_search_properties_is_processed(): void
    {
        $this->fakeDeal([]);

        $this->handle();

        $this->assertSame(1, LogModel::where('message', 'HubSpot: deal ss_smartdoc contacts')->count());
    }

    public function test_a_deal_that_already_holds_a_smartdoc_ssid_is_skipped(): void
    {
        $this->fakeDeal(['smartdoc_ssid' => '100347689']);

        $this->handle();

        $this->assertSame(0, LogModel::where('message', 'HubSpot: deal ss_smartdoc contacts')->count());
    }

    public function test_a_deal_that_already_holds_a_smartdoc_status_is_skipped(): void
    {
        $this->fakeDeal(['smartdoc_status' => 'pending']);

        $this->handle();

        $this->assertSame(0, LogModel::where('message', 'HubSpot: deal ss_smartdoc contacts')->count());
    }

    public function test_a_deal_that_already_holds_an_aml_ssid_is_skipped(): void
    {
        $this->fakeDeal(['smartsearch_uk_individual_ssid' => '100347688']);

        $this->handle('ss_individual_uk');

        $this->assertProcessed('ss_individual_uk', 0);
    }

    public function test_an_aml_ssid_does_not_block_the_smartdoc_checkbox(): void
    {
        $this->fakeDeal(['smartsearch_uk_individual_ssid' => '100347688']);

        $this->handle('ss_smartdoc');

        $this->assertProcessed('ss_smartdoc', 1);
    }

    public function test_smartdoc_properties_do_not_block_the_uk_individual_checkbox(): void
    {
        $this->fakeDeal(['smartdoc_ssid' => '100347689', 'smartdoc_status' => 'pending']);

        $this->handle('ss_individual_uk');

        $this->assertProcessed('ss_individual_uk', 1);
    }

    public function test_empty_search_properties_do_not_count_as_searched(): void
    {
        $this->fakeDeal([
            'smartdoc_ssid' => null,
            'smartdoc_status' => '',
            'smartsearch_uk_individual_ssid' => null,
        ]);

        $this->handle();

        $this->assertSame(1, LogModel::where('message', 'HubSpot: deal ss_smartdoc contacts')->count());
    }
}
