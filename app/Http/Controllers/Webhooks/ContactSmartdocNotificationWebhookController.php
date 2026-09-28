<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\HubSpot\HubSpotWebhookService;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactSmartdocNotificationWebhookController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService,
        protected HubSpotWebhookService $hubSpotWebhookService,
        protected LogService $logService,
    ) {}

    public function __invoke(Request $request): JsonResponse
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
            if (($event['propertyName'] ?? null) === 'smartdoc_notifications_resend') {
                $this->notificationService->handleContactSmartdocNotification($event);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}
