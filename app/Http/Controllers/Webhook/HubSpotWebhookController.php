<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\HubSpotWebhookService;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HubSpotWebhookController extends Controller
{
    public function __construct(
        protected HubSpotWebhookService $hubSpotWebhookService,
        protected LogService $logService,
    ) {}

    /**
     * Handle incoming HubSpot webhook events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hubSpotWebhookService->hasValidSignature($request)) {
            $this->logService->webhook('HubSpot webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('X-HubSpot-Signature-v3'),
                'timestamp' => $request->header('X-HubSpot-Request-Timestamp'),
                'body' => $request->json()->all(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        foreach ($request->json()->all() as $event) {
            $this->hubSpotWebhookService->handleEvent($event);
        }

        return response()->json(['message' => 'ok']);
    }
}
