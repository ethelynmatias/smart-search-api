<?php

namespace App\Services\SmartSearch;

use App\Enums\WebhookDetailStatus;
use App\Models\WebhookDetail;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;
use App\Services\HubSpot\HubSpotService;
use App\Services\LogService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use Carbon\Carbon;

class SmartSearchWebhookService
{
    public function __construct(
        protected WebhookDetailRepositoryInterface $webhookDetails,
        protected LogService $logService,
        protected HubSpotService $hubSpotService,
        protected SmartDocService $smartDocService,
    ) {}

    public function handleCallback(array $payload): void
    {
        $searchId = data_get($payload, 'data.id');

        if (blank($searchId)) {
            $this->logService->webhook('SmartSearch: callback without a search id', $payload);

            return;
        }

        $detail = $this->webhookDetails->findBySsid((string) $searchId);

        if (blank($detail)) {
            $this->logService->webhook('SmartSearch: callback for unknown search', [
                'ssid' => $searchId,
                'response' => $payload,
            ]);

            return;
        }

        $status = WebhookDetailStatus::fromSmartSearch(data_get($payload, 'data.attributes.status'));

        $updated = $this->webhookDetails->markStatusBySsid((string) $searchId, $status, $payload);

        $this->logService->forGroup($detail->group_id)->webhook("SmartSearch: search {$status->value}", [
            'ssid' => $searchId,
            'searchSubjectId' => $detail->search_subject_id,
            'dealId' => $detail->deal_id,
            'type' => $detail->type,
            'previousStatus' => $detail->status?->value,
            'status' => $status->value,
            'updated' => $updated,
            'response' => $payload,
        ]);

        $this->writeStatusToDeal($detail, $status);
        $this->writeResponseToContact($detail, $payload);
        $this->writeStatusToContact($detail, $status);

        if ($status === WebhookDetailStatus::Completed) {
            // Remove notify subject to prevent confusion
            //$this->notifySubject($detail);
            $this->writeRequestDateToDeal($detail, $payload);
        }
    }

    public function handleTripleCheckCallback(array $payload): void
    {
        $contactId = data_get($payload, 'data.attributes.client_reference')
            ?? data_get($payload, 'data.attributes.clientReference')
            ?? data_get($payload, 'data.meta.client_reference')
            ?? data_get($payload, 'meta.client_reference')
            ?? data_get($payload, 'contactId');
        $status = data_get($payload, 'data.attributes.status')
            ?? data_get($payload, 'data.attributes.outcome')
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'outcome');

        if (blank($contactId) || blank($status)) {
            $this->logService->webhook('SmartSearch: TripleCheck callback missing contact or status', [
                'contactId' => $contactId,
                'status' => $status,
                'response' => $payload,
            ]);

            return;
        }

        $response = $this->hubSpotService->updateContactTripleCheckStatus(
            (string) $contactId,
            (string) $status,
        );

        $this->logService->webhook('SmartSearch: TripleCheck contact status written', [
            'contactId' => $contactId,
            'status' => $status,
            'written' => filled($response),
            'response' => $payload,
        ]);
    }

  
    protected function writeResponseToContact(WebhookDetail $detail, array $payload): void
    {
        if ($detail->type !== 'smartdoc' || blank($detail->hubspot_contact_id)) {
            return;
        }

        $response = $this->hubSpotService->updateContactSmartDocResponse(
            $detail->hubspot_contact_id,
            $payload,
        );

        $this->logService->forGroup($detail->group_id)->webhook('HubSpot: contact smartdoc response written', [
            'contactId' => $detail->hubspot_contact_id,
            'ssid' => $detail->ssid,
            // updateContactSmartDocResponse() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    protected function writeStatusToContact(WebhookDetail $detail, WebhookDetailStatus $status): void
    {
        if ($detail->type !== 'smartdoc' || blank($detail->hubspot_contact_id)) {
            return;
        }

        // Update status per contact
        $response = $this->hubSpotService->updateContactSmartDocStatus(
            (string) $detail->hubspot_contact_id,
            $status->value,
        );

        $this->logService->forGroup($detail->group_id)->webhook('HubSpot: contact smartdoc status written', [
            'contactId' => $detail->hubspot_contact_id,
            'ssid' => $detail->ssid,
            'smartdocStatus' => $status->value,
            'written' => filled($response),
        ]);
    }

    protected function notifySubject(WebhookDetail $detail): void
    {
        $email = data_get($detail->payload, 'email');
        $phone = data_get($detail->payload, 'phone');

        [$method, $value] = filled($email) ? ['email', $email] : ['sms', $phone];

        if (blank($detail->search_subject_id) || blank($value)) {
            $this->logService->forGroup($detail->group_id)->webhook('SmartSearch: cannot notify subject', [
                'ssid' => $detail->ssid,
                'searchSubjectId' => $detail->search_subject_id,
                'reason' => blank($detail->search_subject_id)
                    ? 'no search subject id on the detail'
                    : 'no email or phone on the detail',
            ]);

            return;
        }

        try {
            $response = $this->smartDocService->sendNotification($detail->search_subject_id, $method, $value);

            $this->logService->forGroup($detail->group_id)->webhook('SmartSearch: subject notified', [
                'ssid' => $detail->ssid,
                'searchSubjectId' => $detail->search_subject_id,
                'method' => $method,
                'response' => $response,
            ]);
        } catch (SmartSearchException $e) {
            $this->logService->forGroup($detail->group_id)->webhook('SmartSearch: subject notification failed', [
                'ssid' => $detail->ssid,
                'searchSubjectId' => $detail->search_subject_id,
                'method' => $method,
                'status' => $e->status,
                'error' => $e->getMessage(),
                'errors' => $e->errors,
            ]);
        }
    }

    protected function writeRequestDateToDeal(WebhookDetail $detail, array $payload): void
    {
        $createdAt = data_get($payload, 'data.meta.created_at')
            ?? data_get($payload, 'meta.created_at');

        if ($detail->type !== 'smartdoc' || blank($detail->deal_id) || blank($createdAt)) {
            return;
        }

        $date = Carbon::parse($createdAt);

        $response = $this->hubSpotService->updateSmartDocRequestDate($detail->deal_id, $date);

        $this->logService->forGroup($detail->group_id)->webhook('HubSpot: deal smartdoc request date written', [
            'dealId' => $detail->deal_id,
            'ssid' => $detail->ssid,
            'createdAt' => $createdAt,
            'requestDate' => $date->utc()->toDateString(),
            // updateSmartDocRequestDate() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    protected function writeStatusToDeal(WebhookDetail $detail, WebhookDetailStatus $status): void
    {
        if (blank($detail->deal_id)) {
            $this->logService->forGroup($detail->group_id)->webhook('HubSpot: no deal to write the smartdoc status to', [
                'ssid' => $detail->ssid,
                'status' => $status->value,
            ]);

            return;
        }

        // The ssid is already on the property from when the search was created,
        // so this moves that entry's status on and leaves its created date be.
        $response = $this->hubSpotService->updateSmartDocStatus(
            $detail->deal_id,
            $detail->ssid,
            $status->value,
            null,
            $detail->hubspot_contact_id,
        );

        $this->logService->forGroup($detail->group_id)->webhook('HubSpot: deal smartdoc status written', [
            'dealId' => $detail->deal_id,
            'ssid' => $detail->ssid,
            'hubspotContactId' => $detail->hubspot_contact_id,
            'smartdocStatus' => $status->value,
            // updateSmartDocStatus() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }
}
