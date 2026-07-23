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
     * Create a SmartDoc (document verification) search.
     *
     * Returns the response data. Note the SSID and Search-Subject ID —
     * both are required to register the webhook and send the notification link.
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
     * Extract the SSID from a create() response.
     */
    public function ssid(array $result): ?string
    {
        return $result['attributes']['ssid'] ?? $result['id'] ?? null;
    }

    /**
     * Extract the Search-Subject ID from a create() response.
     */
    public function searchSubjectId(array $result): ?string
    {
        return $result['attributes']['search_subject_id']
            ?? $result['relationships']['search_subject']['data']['id']
            ?? null;
    }
}
