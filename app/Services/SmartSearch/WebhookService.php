<?php

namespace App\Services\SmartSearch;

use App\DTOs\SmartSearch\WebhookData;
use App\Models\SmartSearchSearch;
use App\Services\LogService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class WebhookService
{
    public function __construct(
        protected SmartSearchClient $client,
        protected LogService $logService,
    ) {}

    /**
     * Register a webhook for a search so SmartSearch notifies us
     * when the end user completes it.
     *
     * @see https://docs.app.smartsearch.com/#tag/Webhook/operation/SearchWebhookV3Create
     *
     * @throws SmartSearchException
     */
    public function register(string $ssid, string $searchSubjectId, ?string $url = null): array
    {
        return $this->client
            ->post('/v3/webhook', [
                'data' => [
                    'type' => 'webhook',
                    'attributes' => array_filter([
                        'ssid' => $ssid,
                        'search_subject_id' => $searchSubjectId,
                        'url' => $url ?? route('webhooks.smartsearch'),
                    ]),
                ],
            ])
            ->json('data', []);
    }

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
