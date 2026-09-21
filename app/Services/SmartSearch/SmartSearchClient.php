<?php

namespace App\Services\SmartSearch;

use App\Services\SmartSearch\Exceptions\SmartSearchException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SmartSearchClient
{
    public function __construct(
        protected AuthenticationService $auth,
    ) {}

    /**
     * Send an authenticated POST request to the SmartSearch API.
     *
     * @throws SmartSearchException
     */
    public function post(string $endpoint, array $payload = []): Response
    {
        return $this->send('post', $endpoint, $payload);
    }

    /**
     * Send an authenticated GET request to the SmartSearch API.
     *
     * @throws SmartSearchException
     */
    public function get(string $endpoint, array $query = []): Response
    {
        return $this->send('get', $endpoint, $query);
    }

    /**
     * Download a binary file, such as a document PDF, from the SmartSearch API.
     *
     * @throws SmartSearchException
     */
    public function download(string $endpoint): Response
    {
        $response = $this->unauthenticated()
            ->accept('application/pdf')
            ->withToken($this->auth->token())
            ->get($endpoint);

        throw_if($response->failed(), fn () => SmartSearchException::requestFailed($endpoint, $response));

        return $response;
    }

    /**
     * Build a request against the SmartSearch API without authentication.
     *
     * @throws SmartSearchException
     */
    public function unauthenticated(): PendingRequest
    {
        $baseUrl = config('services.smartsearch.base_url');

        throw_if(blank($baseUrl), SmartSearchException::missingConfig('base_url'));

        return Http::baseUrl($baseUrl)
            ->accept('application/vnd.api+json')
            ->contentType('application/vnd.api+json');
    }

    /**
     * @throws SmartSearchException
     */
    protected function send(string $method, string $endpoint, array $data = []): Response
    {
        $response = $this->unauthenticated()
            ->withToken($this->auth->token())
            ->{$method}($endpoint, $data);

        throw_if($response->failed(), fn () => SmartSearchException::requestFailed($endpoint, $response));

        return $response;
    }
}
