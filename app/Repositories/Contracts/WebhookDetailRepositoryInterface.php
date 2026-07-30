<?php

namespace App\Repositories\Contracts;

use App\Enums\WebhookDetailStatus;
use App\Models\WebhookDetail;

interface WebhookDetailRepositoryInterface
{
    /**
     * Persist a new webhook detail.
     */
    public function create(array $attributes): WebhookDetail;

    /**
     * Persist a webhook detail for a search, or return the one already held.
     *
     * @param  array  $attributes  the ssid/type pair identifying the search
     */
    public function firstOrCreate(array $attributes, array $values = []): WebhookDetail;

    /**
     * Find a webhook detail by the SmartSearch search id it is waiting on.
     */
    public function findBySsid(string $ssid): ?WebhookDetail;

    /**
     * Record the outcome of the search an ssid belongs to.
     *
     * @return int the number of details updated
     */
    public function markStatusBySsid(string $ssid, WebhookDetailStatus $status, ?array $payload = null): int;
}
