<?php

namespace App\Services\SmartSearch;

use App\Services\SmartSearch\Exceptions\SmartSearchException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuthenticationService
{
    protected const CACHE_KEY = 'smartsearch_token';

    protected const TOKEN_TTL_MINUTES = 14;

    /**
     * Get a valid SmartSearch access token, cached until shortly before expiry.
     *
     * @throws SmartSearchException
     */
    public function token(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::TOKEN_TTL_MINUTES),
            fn () => $this->requestToken(),
        );
    }

    /**
     * @throws SmartSearchException
     */
    protected function requestToken(): string
    {
        $appId = config('services.smartsearch.app_id');
        $secret = config('services.smartsearch.secret');

        throw_if(blank($appId), SmartSearchException::missingConfig('app_id'));
        throw_if(blank($secret), SmartSearchException::missingConfig('secret'));

        $response = $this->request()->post('/v3/auth/token', [
            'data' => [
                'type' => 'app-token',
                'attributes' => [
                    'app_id' => $appId,
                    'app_secret' => $secret,
                ],
            ],
        ]);

        throw_if($response->failed(), fn () => SmartSearchException::requestFailed('/v3/auth/token', $response));

        $token = $response->json('data.attributes.access_token');

        throw_if(blank($token), new SmartSearchException('SmartSearch auth response did not contain an access token.'));

        return $token;
    }

    /**
     * @throws SmartSearchException
     */
    protected function request(): PendingRequest
    {
        $baseUrl = config('services.smartsearch.base_url');

        throw_if(blank($baseUrl), SmartSearchException::missingConfig('base_url'));

        return Http::baseUrl($baseUrl)
            ->accept('application/vnd.api+json')
            ->contentType('application/vnd.api+json');
    }
}
