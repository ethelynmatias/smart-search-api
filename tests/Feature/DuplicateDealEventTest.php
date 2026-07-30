<?php

namespace Tests\Feature;

use App\Models\Log as LogModel;
use App\Services\HubSpot\HubSpotWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class DuplicateDealEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The same deal closing, delivered three times as HubSpot does.
     */
    protected function event(string $propertyName = 'dealstage'): array
    {
        return [
            'subscriptionType' => 'deal.propertyChange',
            'objectId' => 58338329725,
            'propertyName' => $propertyName,
            'propertyValue' => 'closedlost',
        ];
    }

    protected function handle(array $event): void
    {
        $service = app(HubSpotWebhookService::class);

        (new ReflectionMethod($service, 'handleDealPropertyChange'))->invoke($service, $event);
    }

    public function test_only_the_first_delivery_of_a_deal_event_is_handled(): void
    {
        Http::fake(); // Every HubSpot/SmartSearch call answered with an empty 200.

        $this->handle($this->event());
        $this->handle($this->event('hs_lastmodifieddate'));
        $this->handle($this->event());

        $this->assertSame(
            1,
            LogModel::where('message', 'HubSpot: deal closedlost contacts')->count(),
            'The deal should be handled once however many events arrive.',
        );
    }

    public function test_a_different_property_value_on_the_same_deal_is_handled(): void
    {
        Http::fake(); // Every HubSpot/SmartSearch call answered with an empty 200.

        $this->handle($this->event());
        $this->handle([...$this->event(), 'propertyValue' => 'closedwon']);

        $this->assertSame(1, LogModel::where('message', 'HubSpot: deal closedlost contacts')->count());
        $this->assertSame(1, LogModel::where('message', 'HubSpot: deal closedwon contacts')->count());
    }
}
