<?php

namespace App\Repositories;

use App\Models\WebhookDetail;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;

class WebhookDetailRepository implements WebhookDetailRepositoryInterface
{
    /**
     * Persist a new webhook detail.
     */
    public function create(array $attributes): WebhookDetail
    {
        return WebhookDetail::create($attributes);
    }

    /**
     * Persist a webhook detail for a search, or return the one already held.
     *
     * @param  array  $attributes  the ssid/type pair identifying the search
     */
    public function firstOrCreate(array $attributes, array $values = []): WebhookDetail
    {
        return WebhookDetail::firstOrCreate($attributes, $values);
    }

    /**
     * Find a webhook detail by the SmartSearch search id it is waiting on.
     */
    public function findBySsid(string $ssid): ?WebhookDetail
    {
        return WebhookDetail::query()->where('ssid', $ssid)->latest('id')->first();
    }
}
