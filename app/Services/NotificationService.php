<?php

namespace App\Services;

use App\Services\HubSpot\HubSpotService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\SmartDocService;

class NotificationService
{
    public function __construct(
        protected HubSpotService $hubSpotService,
        protected SmartDocService $smartDocService,
        protected LogService $logService,
    ) {}

    public function handleContactSmartdocNotification(array $payload): void
    {
        $this->handleContactNotification(
            $payload,
            'SmartDoc',
            'smartdoc_subject_id',
            'services.hubspot.notification_token',
            fn (string $contactId) => $this->hubSpotService->updateContactSmartDocLinkExpiryDate($contactId),
        );
    }

    public function handleContactTripleCheckNotification(array $payload): void
    {
        $this->handleContactNotification(
            $payload,
            'TripleCheck',
            'triple_check_ssid',
            'services.hubspot.notification_triple_check_token',
            fn (string $contactId) => $this->hubSpotService->updateContactTripleCheckLinkExpiryDate($contactId),
        );
    }

    protected function handleContactNotification(
        array $payload,
        string $notificationName,
        string $subjectProperty,
        string $notificationTokenConfig,
        callable $updateExpiryDate,
    ): void {
        $contactId = $payload['objectId'] ?? null;

        if (blank($contactId)) {
            $this->logService->webhook("HubSpot contact {$notificationName} notification event has no objectId.", [
                'payload' => $payload,
            ]);

            return;
        }

        $contact = $this->hubSpotService->getContact(
            (string) $contactId,
            notificationToken: true,
            notificationTokenConfig: $notificationTokenConfig,
        );

        if (blank($contact)) {
            return;
        }

        $this->logService->webhook("Fetched HubSpot contact for {$notificationName} notification.", [
            'contactId' => $contactId,
            'properties' => $contact['properties'] ?? [],
        ]);

        $properties = $contact['properties'] ?? [];
        $subjectId = $properties[$subjectProperty] ?? null;
        $mobile = $properties['mobilephone'] ?? null;
        $email = $properties['email'] ?? null;
        [$method, $value] = filled($mobile)
            ? ['sms', (string) $mobile]
            : ['email', (string) $email];

        if (blank($subjectId) || blank($value)) {
            $this->logService->webhook("{$notificationName} notification not sent: contact is missing required details.", [
                'contactId' => $contactId,
                'searchSubjectId' => $subjectId,
                'hasMobile' => filled($mobile),
                'hasEmail' => filled($email),
            ]);

            return;
        }

        try {
            $response = $this->smartDocService->sendNotification(
                (string) $subjectId,
                $method,
                $value,
            );
            $expiryDateUpdate = $updateExpiryDate((string) $contactId);

            $this->logService->webhook("{$notificationName} notification sent.", [
                'contactId' => $contactId,
                'searchSubjectId' => $subjectId,
                'method' => $method,
                'response' => $response,
                'expiryDateUpdated' => filled($expiryDateUpdate),
            ]);
        } catch (SmartSearchException $exception) {
            $this->logService->webhook("{$notificationName} notification failed.", [
                'contactId' => $contactId,
                'searchSubjectId' => $subjectId,
                'method' => $method,
                'status' => $exception->status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
