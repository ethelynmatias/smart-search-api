<?php

namespace App\Services;

use App\Services\HubSpot\HubSpotService;
use App\Services\SmartSearch\SmartDocService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;

class NotificationService
{
    public function __construct(
        protected HubSpotService $hubSpotService,
        protected SmartDocService $smartDocService,
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

        $properties = $contact['properties'] ?? [];
        $subjectId = $properties['smartdoc_subject_id'] ?? null;
        $mobile = $properties['mobilephone'] ?? null;
        $email = $properties['email'] ?? null;
        [$method, $value] = filled($mobile)
            ? ['sms', (string) $mobile]
            : ['email', (string) $email];

        if (blank($subjectId) || blank($value)) {
            $this->logService->webhook('SmartDoc notification not sent: contact is missing required details.', [
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
            $expiryDateUpdate = $this->hubSpotService->updateContactSmartDocLinkExpiryDate(
                (string) $contactId,
            );

            $this->logService->webhook('SmartDoc notification sent.', [
                'contactId' => $contactId,
                'searchSubjectId' => $subjectId,
                'method' => $method,
                'response' => $response,
                'expiryDateUpdated' => filled($expiryDateUpdate),
            ]);

            // Add contact expiry date here 
            
        } catch (SmartSearchException $exception) {
            $this->logService->webhook('SmartDoc notification failed.', [
                'contactId' => $contactId,
                'searchSubjectId' => $subjectId,
                'method' => $method,
                'status' => $exception->status,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
