<?php

namespace App\Services;

use App\Services\HubSpot\HubSpotService;

class NotificationService
{
    public function __construct(
        protected HubSpotService $hubSpotService,
        protected LogService $logService,
    ) {}

    public function handleContactSmartdocNotification(array $payload): void
    {
        $contactId = $payload['objectId'] ?? null;

        if (blank($contactId)) {
            $this->logService->webhook('HubSpot contact notification event has no objectId.', [
                'payload' => $payload,
            ]);

            return;
        }

        $contact = $this->hubSpotService->getContact((string) $contactId, notificationToken: true);

        if (blank($contact)) {
            return;
        }

        $this->logService->webhook('Fetched HubSpot contact for SmartDoc notification.', [
            'contactId' => $contactId,
            'properties' => $contact['properties'] ?? [],
        ]);
    }
}
