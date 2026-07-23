<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\SmartSearch\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartSearchWebhookController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService,
    ) {}

    /**
     * Handle incoming SmartSearch webhook events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->webhookService->handle($request->json()->all());

        return response()->json(['message' => 'ok']);
    }
}
