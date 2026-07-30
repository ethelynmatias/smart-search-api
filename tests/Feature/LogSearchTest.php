<?php

namespace Tests\Feature;

use App\Models\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logs.access_token' => 'test-token']);

        Log::create([
            'log_group_id' => 'group-1',
            'type' => 'webhook',
            'message' => 'HubSpot: deal closedlost smartdoc',
            'payload' => ['dealId' => 58338329725, 'smartdoc' => [['result' => ['data' => ['id' => '100347071']]]]],
        ]);

        Log::create([
            'log_group_id' => 'group-1',
            'type' => 'webhook',
            'message' => 'SmartSearch: search completed',
            'payload' => ['ssid' => '100347071', 'searchSubjectId' => '4ec22476-239c-4a36-8567-92d8e6ced0f4'],
        ]);

        Log::create([
            'log_group_id' => 'group-2',
            'type' => 'webhook',
            'message' => 'HubSpot: deal closedwon smartdoc',
            'payload' => ['dealId' => 11111111111, 'smartdoc' => [['result' => ['data' => ['id' => '999999']]]]],
        ]);
    }

    public function test_searching_by_ssid_finds_every_log_holding_it(): void
    {
        $response = $this->get('/logs/test-token?ssid=100347071');

        $response->assertOk();
        $response->assertSee('HubSpot: deal closedlost smartdoc');
        $response->assertSee('SmartSearch: search completed');
        $response->assertDontSee('HubSpot: deal closedwon smartdoc');
    }

    public function test_searching_by_search_subject_id_works_too(): void
    {
        $this->get('/logs/test-token?ssid=4ec22476-239c-4a36-8567-92d8e6ced0f4')
            ->assertOk()
            ->assertSee('SmartSearch: search completed')
            ->assertDontSee('HubSpot: deal closedwon smartdoc');
    }

    public function test_searching_by_deal_id_works_too(): void
    {
        $this->get('/logs/test-token?ssid=58338329725')
            ->assertOk()
            ->assertSee('HubSpot: deal closedlost smartdoc')
            ->assertDontSee('HubSpot: deal closedwon smartdoc');
    }

    public function test_an_unmatched_search_reports_nothing_found(): void
    {
        $this->get('/logs/test-token?ssid=does-not-exist')
            ->assertOk()
            ->assertSee('Nothing found for');
    }

    public function test_wildcards_are_not_treated_as_a_pattern(): void
    {
        $this->get('/logs/test-token?ssid=%25')
            ->assertOk()
            ->assertSee('Nothing found for');
    }
}
