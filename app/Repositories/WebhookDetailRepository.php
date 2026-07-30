<?php

namespace App\Repositories;

use App\Enums\WebhookDetailStatus;
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

    /**
     * Record the outcome of the search an ssid belongs to.
     *
     * @return int the number of details updated
     */
    public function markStatusBySsid(string $ssid, WebhookDetailStatus $status, ?array $payload = null): int
    {
        // Saved through the model rather than a mass update so the status and
        // payload casts apply; the unique ssid means this is one row anyway.
        return WebhookDetail::query()
            ->where('ssid', $ssid)
            ->get()
            ->each(fn (WebhookDetail $detail) => $detail->update([
                'status' => $status,
                ...($payload === null ? [] : ['payload' => $payload]),
            ]))
            ->count();
    }
}
