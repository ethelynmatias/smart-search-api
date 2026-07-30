<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogPageNotIndexedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logs.access_token' => 'test-token']);
    }

    public function test_the_log_page_tells_crawlers_not_to_index_it(): void
    {
        $response = $this->get('/logs/test-token');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertSee('name="robots"', false);
        $response->assertSee('noindex', false);
    }

    public function test_robots_txt_disallows_the_log_page(): void
    {
        $this->assertStringContainsString(
            'Disallow: /logs/',
            file_get_contents(public_path('robots.txt')),
        );
    }

    public function test_a_wrong_token_is_not_found(): void
    {
        $this->get('/logs/wrong-token')->assertNotFound();
    }
}
