<?php

namespace App\Services\SmartSearch;

use App\DTOs\SmartSearch\NotificationData;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class NotificationService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * List pending notifications from SmartSearch.
     *
     * @return NotificationData[]
     *
     * @throws SmartSearchException
     */
    public function list(): array
    {
        return collect($this->client->get('/v3/notifications')->json('data', []))
            ->map(fn (array $notification) => NotificationData::fromArray($notification))
            ->all();
    }

    /**
     * Retrieve a single notification by its id.
     *
     * @throws SmartSearchException
     */
    public function find(string $id): NotificationData
    {
        return NotificationData::fromArray(
            $this->client->get("/v3/notifications/{$id}")->json('data', []),
        );
    }
}
