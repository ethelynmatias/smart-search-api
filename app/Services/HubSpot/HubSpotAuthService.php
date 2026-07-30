<?php

namespace App\Services\HubSpot;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotAuthService
{
    protected const BASE_URL = 'https://api.hubapi.com';

    /**
     * The private app access token HubSpot requests are made with.
     */
    public function token(): ?string
    {
        return config('services.hubspot.access_token');
    }

    /**
     * Whether HubSpot has been configured at all.
     */
    public function hasToken(): bool
    {
        return filled($this->token());
    }

    /**
     * Build an authenticated HubSpot API client.
     *
     * Returns null when no access token is configured, so callers skip the
     * request rather than send an unauthenticated one that HubSpot rejects.
     *
     * @param  string|null  $context  what the client was wanted for, for the log line
     */
    public function client(?string $context = null): ?PendingRequest
    {
        if (! $this->hasToken()) {
            Log::warning('HubSpot access token is not set; cannot call the HubSpot API.', [
                'context' => $context,
            ]);

            return null;
        }

        return Http::withToken($this->token())->baseUrl(self::BASE_URL);
    }
}
