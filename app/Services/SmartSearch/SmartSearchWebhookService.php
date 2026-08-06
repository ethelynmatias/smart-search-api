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

    /**
     * Handle a SmartSearch search callback, recording the search outcome
     * against the webhook detail that has been waiting on it.
     */
    public function handleCallback(array $payload): void
    {
        $searchId = data_get($payload, 'data.id');

        if (blank($searchId)) {
            $this->logService->webhook('SmartSearch: callback without a search id', $payload);

            return;
        }

        // The detail is read first for its group id, so the callback logs land
        // in the same log group as the HubSpot deal that started the search.
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

        if ($status === WebhookDetailStatus::Completed) {
            $this->notifySubject($detail);
            $this->writeRequestDateToDeal($detail, $payload);
        }
    }

    /**
     * Write the callback payload onto the contact the search was run for.
     */
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
