<?php

namespace Tests\Unit;

use App\Services\HubSpot\HubSpotWebhookService;
use App\Services\SmartSearch\AmlService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class AmlServiceTest extends TestCase
{
    protected string $baseUrl = 'https://api.sandbox.app.smartsearch.com';

    /**
     * A real HubSpot contact payload, as returned by fetchDealContacts().
     */
    protected function contact(array $overrides = []): array
    {
        return [
            'id' => '211509823573',
            // $overrides first: with array union the left operand wins.
            'properties' => $overrides + [
                'zip' => 'SW1A 2AA',
                'city' => 'London',
                'email' => 'ctest@gmail.com',
                'phone' => '3014556608',
                'state' => null,
                'address' => '10 Downing Street',
                'company' => 'dev',
                'country' => null,
                'lastname' => 'test',
                'firstname' => 'ctest',
                'createdate' => '2026-03-25T14:04:00.422Z',
                'salutation' => 'Mr',
                'hs_object_id' => '211509823573',
                'lifecyclestage' => 'customer',
                'lastmodifieddate' => '2026-07-28T10:37:11.851Z',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.smartsearch.base_url', $this->baseUrl);
        Config::set('services.smartsearch.app_id', 'test-app-id');
        Config::set('services.smartsearch.secret', 'test-secret');
    }

    /**
     * Fake the auth call plus a search response of the given status/body.
     */
    protected function fakeSmartSearch(array $body, int $status = 201): void
    {
        Http::fake([
            "{$this->baseUrl}/v3/auth/token" => Http::response(['data' => null, 'meta' => ['token' => 'fake-token']], 201),
            "{$this->baseUrl}/v3/ukindividual/searches" => Http::response($body, $status),
        ]);
    }

    /**
     * Call the protected runAmlSearches() with a list of contacts.
     */
    protected function runAmlSearches(array $contacts): array
    {
        $service = app(HubSpotWebhookService::class);

        $method = new ReflectionMethod($service, 'runAmlSearches');

        return $method->invoke($service, $contacts);
    }

    public function test_search_posts_a_jsonapi_payload_to_the_uk_individual_endpoint(): void
    {
        $this->fakeSmartSearch(['data' => ['id' => 'search-abc', 'type' => 'uk-individual']]);

        $result = app(AmlService::class)->search([
            'title' => 'Mr',
            'first_name' => 'ctest',
            'last_name' => 'test',
            'address1' => '10 Downing Street',
            'city' => 'London',
            'postcode' => 'SW1A 2AA',
            'dob' => '1980-01-01',
        ]);

        $this->assertSame('search-abc', $result['data']['id']);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== "{$this->baseUrl}/v3/ukindividual/searches") {
                return false;
            }

            $this->assertSame('Bearer fake-token', $request->header('Authorization')[0]);
            $this->assertSame('application/vnd.api+json', $request->header('Content-Type')[0]);

            $this->assertSame([
                'data' => [
                    'type' => 'uk-individual',
                    'attributes' => [
                        'name' => [
                            'title' => 'Mr',
                            'first' => 'ctest',
                            'last' => 'test',
                        ],
                        'addresses' => [
                            [
                                'building' => '10 Downing Street',
                                'town' => 'London',
                                'postcode' => 'SW1A 2AA',
                            ],
                        ],
                        'date_of_birth' => '1980-01-01',
                    ],
                ],
            ], $request->data());

            return true;
        });
    }

    public function test_search_sends_a_null_date_of_birth_when_no_dob_is_given(): void
    {
        $this->fakeSmartSearch(['data' => ['id' => 'search-abc']]);

        app(AmlService::class)->search([
            'title' => 'Mr',
            'first_name' => 'ctest',
            'last_name' => 'test',
            'address1' => '10 Downing Street',
            'city' => 'London',
            'postcode' => 'SW1A 2AA',
        ]);

        Http::assertSent(fn (Request $request) => $request->url() === "{$this->baseUrl}/v3/ukindividual/searches"
            && $request->data()['data']['attributes']['date_of_birth'] === null);
    }

    public function test_search_throws_when_the_api_rejects_the_request(): void
    {
        $this->fakeSmartSearch([
            'errors' => [[
                'status' => '400',
                'title' => 'Validation error',
                'detail' => 'This value should not be blank.',
                'source' => ['pointer' => '/data/attributes/name/title'],
            ]],
        ], 400);

        $this->expectException(SmartSearchException::class);
        $this->expectExceptionMessage('This value should not be blank.');

        app(AmlService::class)->search([
            'title' => null,
            'first_name' => 'ctest',
            'last_name' => 'test',
            'address1' => '10 Downing Street',
            'city' => 'London',
            'postcode' => 'SW1A 2AA',
        ]);
    }

    public function test_hubspot_contact_properties_are_mapped_onto_the_search(): void
    {
        $this->fakeSmartSearch(['data' => ['id' => 'search-abc', 'attributes' => ['status' => 'complete']]]);

        $results = $this->runAmlSearches([$this->contact()]);

        $this->assertCount(1, $results);
        $this->assertSame('211509823573', $results[0]['contactId']);
        $this->assertSame('search-abc', $results[0]['result']['data']['id']);
        $this->assertArrayNotHasKey('error', $results[0]);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== "{$this->baseUrl}/v3/ukindividual/searches") {
                return false;
            }

            $attributes = $request->data()['data']['attributes'];

            // salutation/firstname/lastname -> name, address/city/zip -> addresses
            $this->assertSame(['title' => 'Mr', 'first' => 'ctest', 'last' => 'test'], $attributes['name']);
            $this->assertSame([[
                'building' => '10 Downing Street',
                'town' => 'London',
                'postcode' => 'SW1A 2AA',
            ]], $attributes['addresses']);

            // HubSpot fields the endpoint does not accept must not leak through.
            $this->assertArrayNotHasKey('email', $attributes);
            $this->assertArrayNotHasKey('phone', $attributes);
            $this->assertArrayNotHasKey('company', $attributes);
            $this->assertArrayNotHasKey('country', $attributes);

            return true;
        });
    }

    public function test_a_contact_missing_required_fields_is_skipped_without_calling_the_api(): void
    {
        $this->fakeSmartSearch(['data' => ['id' => 'search-abc']]);

        $results = $this->runAmlSearches([
            $this->contact(['salutation' => null, 'zip' => null]),
        ]);

        $this->assertSame('missing required contact fields', $results[0]['skipped']);
        $this->assertSame(['title', 'postcode'], $results[0]['missing']);
        $this->assertArrayNotHasKey('result', $results[0]);

        Http::assertNotSent(fn (Request $request) => $request->url() === "{$this->baseUrl}/v3/ukindividual/searches");
    }

    public function test_a_failed_search_is_recorded_instead_of_throwing(): void
    {
        $this->fakeSmartSearch(['errors' => [['status' => '500', 'title' => 'Internal Server Error']]], 500);

        $results = $this->runAmlSearches([$this->contact()]);

        $this->assertSame('211509823573', $results[0]['contactId']);
        $this->assertSame(500, $results[0]['status']);
        $this->assertStringContainsString('Internal Server Error', $results[0]['error']);
        $this->assertArrayNotHasKey('result', $results[0]);
    }

    public function test_every_contact_is_searched_even_when_one_fails(): void
    {
        Http::fake([
            "{$this->baseUrl}/v3/auth/token" => Http::response(['meta' => ['token' => 'fake-token']], 201),
            "{$this->baseUrl}/v3/ukindividual/searches" => Http::sequence()
                ->push(['errors' => [['status' => '500', 'title' => 'Internal Server Error']]], 500)
                ->push(['data' => ['id' => 'search-two']], 201),
        ]);

        $results = $this->runAmlSearches([
            $this->contact(),
            ['id' => '999', 'properties' => [
                'salutation' => 'Mrs',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'address' => '221B Baker Street',
                'city' => 'London',
                'zip' => 'NW1 6XE',
            ]],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(500, $results[0]['status']);
        $this->assertSame('search-two', $results[1]['result']['data']['id']);
    }
}
