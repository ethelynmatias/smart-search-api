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
     * Send the search link to the end user (SMS by default, or email)
     * so they can upload their identity document and complete a selfie.
     *
     * @see https://docs.app.smartsearch.com/#tag/Notification/operation/NotificationV3Create
     *
     * @throws SmartSearchException
     */
    public function create(NotificationData $data): array
    {
        return $this->client
            ->post('/v3/notifications', $data->toPayload())
            ->json('data', []);
    }
}
