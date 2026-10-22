<?php

namespace Tests\Unit;

use App\Services\HubSpot\HubSpotService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubSpotServiceTest extends TestCase
{
    protected string $baseUrl = 'https://api.hubapi.com';

    protected string $contactId = '211509823573';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.hubspot.access_token', 'test-access-token');
    }

    /**
     * Fake the contact read with the smartdoc_ssid already on the contact,
     * plus the patch the write would make.
     */
    protected function fakeContact(?string $existing, int $status = 200): void
    {
        Http::fake([
            "{$this->baseUrl}/crm/v3/objects/contacts/{$this->contactId}*" => Http::sequence()
                ->push(['id' => $this->contactId, 'properties' => ['smartdoc_ssid' => $existing]], $status)
                ->push(['id' => $this->contactId, 'properties' => ['smartdoc_ssid' => 'new-ssid']], 200),
        ]);
    }

    /**
     * The properties sent by the patch, or null when nothing was patched.
     */
    protected function patchedProperties(): ?array
    {
        $patched = null;

        Http::assertSent(function (Request $request) use (&$patched) {
            if ($request->method() === 'PATCH') {
                $patched = $request->data()['properties'] ?? [];
            }

            return true;
        });

        return $patched;
    }

    public function test_a_contact_with_no_smartdoc_ssid_is_written_to(): void
    {
        $this->fakeContact(null);

        $response = app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertSame(['smartdoc_ssid' => 'new-ssid'], $this->patchedProperties());
        $this->assertSame($this->contactId, $response['id']);
    }

    public function test_a_contact_that_already_holds_a_smartdoc_ssid_is_left_alone(): void
    {
        $this->fakeContact('100347689');

        $response = app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertNull($this->patchedProperties());
        $this->assertSame([], $response);
    }

    public function test_a_skipped_contact_is_written_over(): void
    {
        $this->fakeContact('skipped');

        app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertSame(['smartdoc_ssid' => 'new-ssid'], $this->patchedProperties());
    }

    public function test_the_skipped_marker_is_matched_whatever_its_case_and_spacing(): void
    {
        $this->fakeContact('  Skipped ');

        app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertSame(['smartdoc_ssid' => 'new-ssid'], $this->patchedProperties());
    }

    public function test_an_empty_string_counts_as_no_content(): void
    {
        $this->fakeContact('');

        app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertSame(['smartdoc_ssid' => 'new-ssid'], $this->patchedProperties());
    }

    public function test_a_read_that_fails_does_not_block_the_write(): void
    {
        $this->fakeContact(null, 500);

        app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        $this->assertSame(['smartdoc_ssid' => 'new-ssid'], $this->patchedProperties());
    }

    public function test_nothing_is_read_or_written_without_an_access_token(): void
    {
        Config::set('services.hubspot.access_token', null);

        Http::fake();

        $response = app(HubSpotService::class)->updateContactSmartDocSsid($this->contactId, 'new-ssid');

        Http::assertNothingSent();
        $this->assertSame([], $response);
    }

    public function test_triple_check_status_is_written_to_a_contact(): void
    {
        Http::fake([
            "{$this->baseUrl}/crm/v3/objects/contacts/{$this->contactId}" => Http::response([
                'id' => $this->contactId,
                'properties' => ['triple_check_status' => 'clear'],
            ]),
        ]);

        $response = app(HubSpotService::class)->updateContactTripleCheckStatus(
            $this->contactId,
            'clear',
        );

        $this->assertSame(['triple_check_status' => 'clear'], $this->patchedProperties());
        $this->assertSame($this->contactId, $response['id']);
    }

    public function test_triple_check_ssid_is_written_to_a_contact(): void
    {
        Http::fake([
            "{$this->baseUrl}/crm/v3/objects/contacts/{$this->contactId}" => Http::response([
                'id' => $this->contactId,
                'properties' => ['triple_check_ssid' => 'triple-check-123'],
            ]),
        ]);

        $response = app(HubSpotService::class)->updateContactTripleCheckSsid(
            $this->contactId,
            'triple-check-123',
        );

        $this->assertSame(['triple_check_ssid' => 'triple-check-123'], $this->patchedProperties());
        $this->assertSame($this->contactId, $response['id']);
    }

    public function test_triple_check_link_expiry_date_is_written_to_a_contact(): void
    {
        Http::fake([
            "{$this->baseUrl}/crm/v3/objects/contacts/{$this->contactId}" => Http::response([
                'id' => $this->contactId,
                'properties' => ['triple_check_link_expiry_date' => '2026-10-25T12:30:00+00:00'],
            ]),
        ]);

        $response = app(HubSpotService::class)->updateContactTripleCheckLinkExpiryDate(
            $this->contactId,
            Carbon::parse('2026-10-25 12:30:00', 'UTC'),
        );

        $this->assertSame([
            'triple_check_link_expiry_date' => '2026-10-25T12:30:00+00:00',
        ], $this->patchedProperties());
        $this->assertSame($this->contactId, $response['id']);
    }

    public function test_smart_search_triple_check_response_is_written_to_a_contact(): void
    {
        $tripleCheckResponse = [
            'data' => null,
            'meta' => [
                'subject' => '399f4357-d796-483a-8543-e38e35684528',
                'version' => 'v3.9.91',
                'duration' => 6.996,
                'smartdoc' => '100395145',
                'reference' => '7dd3af54-fdef-421b-8bad-637d384cea87',
                'timestamp' => '2026-10-21T14:13:24+00:00',
            ],
        ];

        Http::fake([
            "{$this->baseUrl}/crm/v3/objects/contacts/{$this->contactId}" => Http::response([
                'id' => $this->contactId,
                'properties' => ['smart_search_triple_check' => json_encode($tripleCheckResponse)],
            ]),
        ]);

        $hubSpotResponse = app(HubSpotService::class)->updateContactSmartSearchTripleCheck(
            $this->contactId,
            $tripleCheckResponse,
        );

        $this->assertSame([
            'smart_search_triple_check' => json_encode([
                'data' => null,
                'meta' => [
                    'subject' => '399f4357-d796-483a-8543-e38e35684528',
                    'version' => 'v3.9.91',
                    'duration' => 6.996,
                    'smartdoc' => '100395145',
                    'reference' => '7dd3af54-fdef-421b-8bad-637d384cea87',
                    'timestamp' => '2026-10-21T14:13:24+00:00',
                ],
            ]),
        ], $this->patchedProperties());
        $this->assertSame($this->contactId, $hubSpotResponse['id']);
    }
}
