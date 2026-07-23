<?php

namespace App\Services\SmartSearch;

use App\Services\SmartSearch\DTOs\UKIndividualRequest;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class UKIndividualService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Run a UK individual AML check.
     *
     * @throws SmartSearchException
     */
    public function create(UKIndividualRequest $request): array
    {
        return $this->client
            ->post('/v3/ukindividual', $request->toPayload())
            ->json('data', []);
    }

    /**
     * Retrieve an existing UK individual check by its id.
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
