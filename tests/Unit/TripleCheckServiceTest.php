<?php

namespace Tests\Unit;

use App\Services\SmartSearch\TripleCheckService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripleCheckServiceTest extends TestCase
{
    protected string $baseUrl = 'https://api.sandbox.app.smartsearch.com';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set([
            'services.smartsearch.base_url' => $this->baseUrl,
            'services.smartsearch.app_id' => 'test-app-id',
            'services.smartsearch.secret' => 'test-secret',
            'services.smartsearch.group_id' => 'group-id',
            'services.smartsearch.uk_individual_config_id' => 'uk-config-id',
            'services.smartsearch.fraud_check_config_id' => 'fraud-config-id',
            'services.smartsearch.smart_doc_config_id' => 'smart-doc-config-id',
        ]);
    }

    public function test_create_posts_the_triplecheck_jsonapi_payload(): void
    {
        Http::fake([
            "{$this->baseUrl}/v3/auth/token" => Http::response([
                'meta' => ['token' => 'fake-token'],
            ], 201),
            "{$this->baseUrl}/v3/triplecheck/searches" => Http::response([
                'data' => ['id' => 'triplecheck-123', 'type' => 'triplecheck'],
            ], 201),
        ]);

        $result = app(TripleCheckService::class)->create([
            'client_reference' => 'contact-123',
            'title' => 'Mr',
            'first_name' => 'John',
            'middle_name' => 'A',
            'last_name' => 'Smith',
            'dob' => '1980-01-01',
            'sex' => 'male',
            'mobile' => '+44 7700 900123',
            'email' => 'john@example.com',
            'flat' => '2',
            'address1' => '10 Downing Street',
            'street_1' => 'Whitehall',
            'street_2' => null,
            'city' => 'London',
            'region' => 'London',
            'postcode' => 'SW1A 2AA',
            'search_subject_id' => 'subject-123',
        ]);

        $this->assertSame('triplecheck-123', $result['data']['id']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === "{$this->baseUrl}/v3/triplecheck/searches"
                && $request->header('Authorization')[0] === 'Bearer fake-token'
                && $request->data() === [
                    'data' => [
                        'type' => 'triplecheck',
                        'attributes' => [
                            'client_reference' => 'contact-123',
                            'date_of_birth' => '1980-01-01',
                            'sex' => 'male',
                            'document_types' => ['passport', 'driving-licence', 'id-card'],
                            'scan_type' => 'advanced',
                            'name' => [
                                'title' => 'Mr',
                                'first' => 'John',
                                'middle' => 'A',
                                'last' => 'Smith',
                            ],
                            'contacts' => [
                                'mobile' => '+447700900123',
                                'email' => 'john@example.com',
                            ],
                            'addresses' => [[
                                'flat' => '2',
                                'building' => '10 Downing Street',
                                'street_1' => 'Whitehall',
                                'street_2' => null,
                                'town' => 'London',
                                'region' => 'London',
                                'postcode' => 'SW1A 2AA',
                                'duration' => 1,
                            ]],
                        ],
                    ],
                ];
        });
    }
}
