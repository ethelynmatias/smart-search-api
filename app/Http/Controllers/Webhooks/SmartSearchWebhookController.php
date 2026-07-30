<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookDetailStatus;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SmartSearchWebhookController extends Controller
{
    public function __construct(
        protected WebhookDetailRepositoryInterface $webhookDetails,
        protected LogService $logService,
    ) {}

    /**
     * Handle a SmartSearch search callback, recording the search outcome
     * against the webhook detail that has been waiting on it.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        $searchId = data_get($payload, 'data.id');

        if (blank($searchId)) {
            $this->logService->webhook('SmartSearch: callback without a search id', $payload);

            return response()->noContent();
        }

        // The detail is read first for its group id, so the callback logs land
        // in the same log group as the HubSpot deal that started the search.
        $detail = $this->webhookDetails->findBySsid((string) $searchId);

        if (blank($detail)) {
            $this->logService->webhook('SmartSearch: callback for unknown search', [
                'ssid' => $searchId,
                'response' => $payload,
            ]);

            return response()->noContent();
        }

        $status = WebhookDetailStatus::fromSmartSearch(data_get($payload, 'data.attributes.status'));

        $updated = $this->webhookDetails->markStatusBySsid((string) $searchId, $status, $payload);

        $this->logService->webhook(
            "SmartSearch: search {$status->value}",
            [
                'ssid' => $searchId,
                'searchSubjectId' => $detail->search_subject_id,
                'dealId' => $detail->deal_id,
                'type' => $detail->type,
                'previousStatus' => $detail->status?->value,
                'status' => $status->value,
                'updated' => $updated,
                'response' => $payload,
            ],
            $detail->group_id,
        );

        return response()->noContent();
    }
}
