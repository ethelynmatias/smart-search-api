<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HubSpotWebhookController extends Controller
{
    public function __construct(
        protected LogService $logService,
    ) {}

    /**
     * Handle incoming HubSpot webhook events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            $this->logService->webhook('HubSpot webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('X-HubSpot-Signature-v3'),
                'timestamp' => $request->header('X-HubSpot-Request-Timestamp'),
                'body' => $request->json()->all(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $events = $request->json()->all();

        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Dispatch a single webhook event by subscription type.
     */
    protected function handleEvent(array $event): void
    {
        $type = $event['subscriptionType'] ?? 'unknown';

        $this->logService->webhook("HubSpot: {$type}", $event);

        match ($type) {
            // 'contact.creation' => ...,
            // 'contact.deletion' => ...,
            // 'contact.propertyChange' => ...,
            default => Log::debug('Unhandled HubSpot webhook event', ['type' => $type]),
        };
    }

    /**
     * Verify the X-HubSpot-Signature-v3 header.
     *
     * @see https://developers.hubspot.com/docs/api/webhooks/validating-requests
     */
    protected function hasValidSignature(Request $request): bool
    {
        $secret = config('services.hubspot.client_secret');

        if (blank($secret)) {
            Log::warning('HubSpot webhook received but services.hubspot.client_secret is not set.');

            return false;
        }

        $signature = $request->header('X-HubSpot-Signature-v3');
        $timestamp = $request->header('X-HubSpot-Request-Timestamp');

        if (blank($signature) || blank($timestamp)) {
            return false;
        }

        // Reject requests older than 5 minutes to prevent replay attacks
        if (abs(now()->getTimestampMs() - (int) $timestamp) > 300_000) {
            return false;
        }

        $source = $request->method().$request->fullUrl().$request->getContent().$timestamp;
        $expected = base64_encode(hash_hmac('sha256', $source, $secret, true));

        return hash_equals($expected, $signature);
    }
}
