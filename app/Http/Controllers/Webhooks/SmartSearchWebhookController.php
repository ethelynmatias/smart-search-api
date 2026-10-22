<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\HubSpot\HubSpotWebhookService;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\SmartSearch\SmartSearchWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SmartSearchWebhookController extends Controller
{
    public function __construct(
        protected SmartSearchWebhookService $smartSearchWebhookService,
        protected NotificationService $notificationService,
        protected HubSpotWebhookService $hubSpotWebhookService,
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

    /**
     * Handle a TripleCheck callback and write its status to the HubSpot contact.
     */
    public function handleTripleCheck(Request $request): Response
    {
        $payload = $request->all();

        $this->logService->webhook('SmartSearch: TripleCheck callback received', [
            'ip' => $request->ip(),
            'contentType' => $request->header('Content-Type'),
            'raw' => blank($payload) ? $request->getContent() : null,
        ]);

        $this->smartSearchWebhookService->handleTripleCheckCallback($payload);

        return response()->noContent();
    }

    /**
     * Handle HubSpot contact SmartDoc notification resend events.
     */
    public function handleContactSmartdocNotification(Request $request): JsonResponse
    {
        $events = $request->json()->all();
        $hasNotificationEvent = collect($events)->contains(
            fn ($event) => ($event['propertyName'] ?? null) === 'smartdoc_notifications_resend',
        );

        if (! $this->hubSpotWebhookService->hasValidSignature($request, $hasNotificationEvent)) {
            $this->logService->webhook('HubSpot webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('X-HubSpot-Signature-v3'),
                'timestamp' => $request->header('X-HubSpot-Request-Timestamp'),
                'body' => $request->json()->all(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        foreach ($events as $event) {
            if (($event['propertyName'] ?? null) === 'smartdoc_notifications_resend'
                && in_array($event['propertyValue'] ?? null, ['true', true], true)) {
                $this->notificationService->handleContactSmartdocNotification($event);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}
