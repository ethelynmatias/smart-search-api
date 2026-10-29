<?php

namespace Tests\Unit;

use App\Services\SmartSearch\SmartDocService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartDocServiceTest extends TestCase
{
    protected string $baseUrl = 'https://api.sandbox.app.smartsearch.com';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('services.smartsearch.base_url', $this->baseUrl);
        Config::set('services.smartsearch.app_id', 'test-app-id');
        Config::set('services.smartsearch.secret', 'test-secret');
    }

    public function test_send_notification_posts_subject_notification_for_search_subject(): void
    {
        $subjectId = '57282102-035f-41a4-bdc8-c7f2eb8b4256';

        Http::fake([
            "{$this->baseUrl}/v3/auth/token" => Http::response([
                'meta' => ['token' => 'fake-token'],
            ], 201),
            "{$this->baseUrl}/v3/notifications" => Http::response([
                'data' => ['id' => 'notification-123', 'type' => 'subject-notification'],
            ], 201),
        ]);

        $result = app(SmartDocService::class)->sendNotification($subjectId, 'email', 'email');

        $this->assertSame('notification-123', $result['data']['id']);

        Http::assertSent(function (Request $request) use ($subjectId): bool {
            return $request->method() === 'POST'
                && $request->url() === "{$this->baseUrl}/v3/notifications"
                && $request->data() === [
                    'data' => [
                        'type' => 'subject-notification',
                        'attributes' => [
                            'method' => 'email',
                            'value' => 'email',
                        ],
                        'relationships' => [
                            'subject' => [
                                'data' => [
                                    'type' => 'search-subject',
                                    'id' => $subjectId,
                                ],
                            ],
                        ],
                    ],
                ];
        });
    }

    public function test_get_retrieves_a_smartdoc_search_by_id(): void
    {
        $searchId = 'smartdoc-123';

        Http::fake([
            "{$this->baseUrl}/v3/auth/token" => Http::response([
                'meta' => ['token' => 'fake-token'],
            ], 201),
            "{$this->baseUrl}/v3/smartdoc/searches/{$searchId}" => Http::response([
                'data' => [
                    'id' => $searchId,
                    'type' => 'smartdoc',
                    'attributes' => ['status' => 'complete'],
                ],
            ]),
        ]);

        $result = app(SmartDocService::class)->get($searchId);

        $this->assertSame($searchId, $result['data']['id']);
        $this->assertSame('complete', $result['data']['attributes']['status']);

        Http::assertSent(function (Request $request) use ($searchId): bool {
            return $request->method() === 'GET'
                && $request->url() === "{$this->baseUrl}/v3/smartdoc/searches/{$searchId}"
                && $request->header('Authorization')[0] === 'Bearer fake-token'
                && $request->header('Accept')[0] === 'application/vnd.api+json';
        });
    }
}
