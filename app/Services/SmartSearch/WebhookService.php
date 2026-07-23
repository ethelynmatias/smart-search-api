<?php

namespace App\Services\SmartSearch;

use App\DTOs\SmartSearch\WebhookData;
use App\Models\SmartSearchSearch;
use App\Services\LogService;

class WebhookService
{
    public function __construct(
        protected LogService $logService,
    ) {}

    /**
     * Handle an incoming SmartSearch webhook payload.
     */
    public function handle(array $payload): WebhookData
    {
        $data = WebhookData::fromArray($payload);

        $this->logService->webhook("SmartSearch: {$data->event}", $data->toArray());

        if (filled($data->searchId)) {
            SmartSearchSearch::query()
                ->where('search_id', $data->searchId)
                ->update(array_filter([
                    'status' => $data->status,
                    'result' => $data->raw,
                ]));
        }

        return $data;
    }
}
