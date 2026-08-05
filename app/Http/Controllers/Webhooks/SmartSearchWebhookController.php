<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\LogService;
use App\Services\SmartSearch\SmartSearchWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SmartSearchWebhookController extends Controller
{
    public function __construct(
        protected SmartSearchWebhookService $smartSearchWebhookService,
        protected LogService $logService,
    ) {}

    /**
     * Handle a SmartSearch search callback.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        $this->logService->webhook('SmartSearch: search callback received', [
            'ip' => $request->ip(),
            'contentType' => $request->header('Content-Type'),
            'raw' => blank($payload) ? $request->getContent() : null,
        ]);

        $this->smartSearchWebhookService->handleCallback($payload);

        return response()->noContent();
    }
}
