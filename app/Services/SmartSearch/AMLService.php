<?php

namespace App\Services\SmartSearch;

use App\DTOs\SmartSearch\AMLData;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class AMLService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Run an AML (UK individual) check.
     *
     * @throws SmartSearchException
     */
    public function create(AMLData $data): array
    {
        return $this->client
            ->post('/v3/ukindividual', $data->toPayload())
            ->json('data', []);
    }

    /**
     * Retrieve an existing AML check by its id.
     *
     * @throws SmartSearchException
     */
    public function find(string $id): array
    {
        return $this->client
            ->get("/v3/ukindividual/{$id}")
            ->json('data', []);
    }
}
