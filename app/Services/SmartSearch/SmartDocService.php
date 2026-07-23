<?php

namespace App\Services\SmartSearch;

use App\DTOs\SmartSearch\SmartDocData;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class SmartDocService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Create a SmartDoc (document verification) check.
     *
     * @throws SmartSearchException
     */
    public function create(SmartDocData $data): array
    {
        return $this->client
            ->post('/v3/smartdoc', $data->toPayload())
            ->json('data', []);
    }

    /**
     * Retrieve an existing SmartDoc check by its id.
     *
     * @throws SmartSearchException
     */
    public function find(string $id): array
    {
        return $this->client
            ->get("/v3/smartdoc/{$id}")
            ->json('data', []);
    }
}
