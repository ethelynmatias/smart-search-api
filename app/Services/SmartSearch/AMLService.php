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
}
