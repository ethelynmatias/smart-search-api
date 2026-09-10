<?php

namespace App\Services\HubSpot;

use App\Enums\WebhookDetailStatus;
use App\Models\HubSpotWebhookEvent;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;
use App\Services\LogService;
use App\Services\SmartSearch\AmlService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\FraudCheckService;
use App\Services\SmartSearch\SmartDocService;
use App\Support\HubSpotProperty;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HubSpotWebhookService
{
    /**
     * Contact fields the AML search cannot run without.
     */
    protected const AML_REQUIRED_FIELDS = ['title', 'first_name', 'last_name', 'address1', 'city', 'postcode'];

    /**
     * Contact fields the SmartDoc verification cannot be created without.
     */
    protected const SMARTDOC_REQUIRED_FIELDS = ['first_name', 'last_name', 'building', 'town', 'postcode', 'date_of_birth', 'sex'];

    /**
     * Contact fields the fraud check cannot run without.
     */
    protected const FRAUD_CHECK_REQUIRED_FIELDS = ['title', 'first_name', 'last_name', 'address1', 'city', 'postcode', 'mobile'];

    protected const OWNER_TITLE = 'Mr';

    protected const AML_LABELS = ['director', 'psc'];

    /**
     * Deal properties each checkbox writes its search results to, keyed by the
     * checkbox that triggers them. Any of a checkbox's own properties holding a
     * value means that search has run already; the other checkbox is
     * unaffected, so both can still run on the same deal.
     */
    protected const SEARCH_PROPERTIES = [
        'smart_search' => ['smartdoc_ssid', 'smartdoc_status', 'smartsearch_uk_individual_ssid'],
    ];

    public function __construct(
        protected LogService $logService,
        protected AmlService $amlService,
        protected SmartDocService $smartDocService,
        protected FraudCheckService $fraudCheckService,
        protected WebhookDetailRepositoryInterface $webhookDetails,
        protected HubSpotAuthService $hubSpotAuth,
        protected HubSpotService $hubSpotService,
    ) {}

    /**
     * Verify the X-HubSpot-Signature-v3 header.
     *
     * @see https://developers.hubspot.com/docs/api/webhooks/validating-requests
     */
    public function hasValidSignature(Request $request): bool
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

    /**
     * Dispatch a single webhook event by subscription type.
     */
    public function handleEvent(array $event): void
    {
        $type = $event['subscriptionType'] ?? 'unknown';

        if (! $this->isNewEvent($event)) {
            return;
        }

        if ($type !== 'deal.propertyChange') {
            $this->logService->webhook("HubSpot: {$type}", $event);
        }

        match ($type) {
            'deal.propertyChange' => $this->handleDealPropertyChange($event),
            default => Log::debug('Unhandled HubSpot webhook event', ['type' => $type]),
        };
    }

    /**
     * the event run once however many copies of it arrive.
     */
    protected function isNewEvent(array $event): bool
    {
        $eventId = $event['eventId'] ?? null;

        // Nothing to key on. Rather than drop the event, let it through and
        // leave the deal property checks to catch a repeat of it.
        if (blank($eventId)) {
            Log::warning('HubSpot webhook event has no eventId to deduplicate on.', $event);

            return true;
        }

        try {
            $record = HubSpotWebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'object_id' => $event['objectId'] ?? null,
                    'subscription_type' => $event['subscriptionType'] ?? null,
                    'property_name' => $event['propertyName'] ?? null,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // The other delivery inserted between our select and our insert.
            $record = null;
        }

        if (blank($record) || ! $record->wasRecentlyCreated) {
            Log::debug('HubSpot webhook event already processed; skipping.', [
                'eventId' => $eventId,
                'objectId' => $event['objectId'] ?? null,
                'subscriptionType' => $event['subscriptionType'] ?? null,
                'propertyName' => $event['propertyName'] ?? null,
                'attemptNumber' => $event['attemptNumber'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Whether an event is one this app acts on, and so worth a log line.
     */
    protected function isActionable(array $event): bool
    {
        if (($event['subscriptionType'] ?? null) !== 'deal.propertyChange') {
            return true;
        }

        return in_array($event['propertyName'] ?? null, array_keys(self::SEARCH_PROPERTIES), true)
            && in_array($event['propertyValue'] ?? null, ['true', true], true);
    }

    /**
     * Handle a deal property change. When ss_smartdoc or ss_individual_uk is
     * ticked, fetch its associated contacts and log their fields.
     */
    protected function handleDealPropertyChange(array $event): void
    {
        $property = $event['propertyName'] ?? null;
        $value = $event['propertyValue'] ?? null;

        if (! $this->isActionable($event)) {
            return;
        }

        $dealId = $event['objectId'] ?? null;

        if (blank($dealId)) {
            return;
        }

        $deal = $this->fetchDeal((string) $dealId);

        if (filled($searched = $this->searchPropertiesOn($deal, $property))) {
            Log::debug('HubSpot deal already searched; skipping.', [
                'dealId' => $dealId,
                'propertyName' => $property,
                'propertyValue' => $value,
                'properties' => $searched,
            ]);

            return;
        }

        $this->logService->webhook('HubSpot: deal.propertyChange', $event);

        // $contacts = $this->fetchDealContacts((string) $dealId);
        $company = $this->fetchDealCompany((string) $dealId);

        // contacts associated with it.
        $companyId = $company['id'] ?? null;

        $contacts = filled($companyId)
            ? $this->fetchCompanyContacts((string) $companyId)
            : [];

        $this->logService->webhook("HubSpot: deal {$property} contacts", [
            'dealId' => $dealId,
            'deal' => $deal,
            'companyId' => $companyId,
            'contacts' => $contacts,
            'company' => $company,
            'amlContactIds' => collect($this->amlContacts($contacts))->pluck('id')->all(),
        ]);

        // Only the directors and PSCs are put through AML; everyone else on the
        // company is verified with SmartDoc alone.
        $amlContacts = $this->amlContacts($contacts);

        $aml = $property === 'smart_search' && filled($amlContacts)
            ? $this->runAmlSearches($amlContacts)
            : [];

        if (filled($aml)) {
            // Fold the results back into the same log record, so the whole deal

            $amlLog = $this->logService->webhook("HubSpot: deal {$property} aml", [
                'dealId' => $dealId,
                'propertyName' => $property,
                'aml' => $aml,
            ]);

            $this->writeAmlSsidsToDeal((string) $dealId, $aml, $amlLog->log_group_id);
            $this->writeUkIndividualRequestDateToDeal((string) $dealId, $aml, $amlLog->log_group_id);
        }

        $smartDoc = $property === 'smart_search' && filled($contacts)
            ? $this->runSmartDocSearches($contacts)
            : [];

        if (blank($smartDoc)) {
            return;
        }

        $smartDocLog = $this->logService->webhook("HubSpot: deal {$property} smartdoc", [
            'dealId' => $dealId,
            'propertyName' => $property,
            'smartdoc' => $smartDoc,
        ]);

        $this->recordSmartDocDetails((string) $dealId, $smartDoc, $smartDocLog->log_group_id);

        // Runs after the SmartDoc details are recorded, since the fraud check id
        // joins the row that search created rather than opening one of its own.
        $fraudChecks = $this->runFraudChecks($contacts);

        if (blank($fraudChecks)) {
            return;
        }

        $fraudCheckLog = $this->logService->webhook("HubSpot: deal {$property} fraud check", [
            'dealId' => $dealId,
            'propertyName' => $property,
            'fraudChecks' => $fraudChecks,
        ]);

        $this->recordFraudCheckDetails((string) $dealId, $fraudChecks, $fraudCheckLog->log_group_id);
    }

    /**
     * Persist one pending webhook detail per created SmartDoc search, so the
     * result callback can be matched back to its deal by ssid.
     */
    protected function recordSmartDocDetails(string $dealId, array $smartDoc, ?string $groupId): void
    {
        $ssids = [];

        foreach ($smartDoc as $entry) {
            $result = $entry['result'] ?? null;

            $ssid = data_get($result, 'data.id');

            if (blank($ssid)) {
                // Nothing to wait on, but the subject should still be able to
                // see why their verification never started.
                $this->writeSmartDocErrorsToContact($entry, $groupId);

                continue;
            }

            // Keyed on the ssid so a webhook HubSpot redelivers, or a deal that
            // closes twice, does not leave a second row waiting on one search.
            $detail = $this->webhookDetails->firstOrCreate(
                [
                    'ssid' => (string) $ssid,
                    'type' => 'smartdoc',
                ],
                [
                    'group_id' => $groupId,
                    'deal_id' => $dealId,
                    'hubspot_contact_id' => $entry['contactId'] ?? null,
                    'search_subject_id' => data_get($result, 'data.relationships.subject.data.id'),
                    'status' => WebhookDetailStatus::Pending,
                    'payload' => $entry,
                ],
            );

            if ($detail->wasRecentlyCreated) {
                $this->registerSmartDocWebhook((string) $ssid, $groupId);

                // The deal carries a status per search, so every subject's ssid
                // gets its own entry rather than sharing one.
                $createdAt = data_get($result, 'data.meta.created_at');

                $this->writeSmartDocStatusToDeal(
                    $dealId,
                    (string) $ssid,
                    $detail->status,
                    filled($createdAt) ? Carbon::parse($createdAt) : null,
                    $groupId,
                    $detail->hubspot_contact_id,
                );

                $this->writeSmartDocSsidToContact((string) $ssid, $detail->hubspot_contact_id, $groupId);
            }

            $ssids[] = (string) $ssid;
        }

        if (filled($ssids)) {
            $this->writeSmartDocSsidsToDeal($dealId, $ssids, $groupId);
        }
    }

    /**
     * Write the UK individual AML search ids back onto the deal in HubSpot.
     */
    protected function writeAmlSsidsToDeal(string $dealId, array $aml, ?string $groupId): void
    {
        $ssids = collect($aml)
            ->map(fn (array $entry) => data_get($entry, 'result.data.id'))
            ->filter()
            ->map(fn ($ssid) => (string) $ssid)
            ->unique()
            ->values();

        if ($ssids->isEmpty()) {
            return;
        }

        $value = $ssids->implode(',');

        $response = $this->hubSpotService->updateSmartSearchUkIndividualSsid($dealId, $value);

        $this->logService->forGroup($groupId)->webhook('HubSpot: deal aml ssid written', [
            'dealId' => $dealId,
            'smartSearchUkIndividualSsid' => $value,
            // updateSmartSearchUkIndividualSsid() logs its own failure and returns empty.
            'written' => filled($response),
        ]);

        // The deal holds the whole set; each contact holds its own search.
        foreach ($aml as $entry) {
            $this->writeAmlToContact($entry, $groupId);
        }
    }

    /**
     * Write one AML search — its id and the response it came back with — onto
     */
    protected function writeAmlToContact(array $entry, ?string $groupId): void
    {
        $contactId = $entry['contactId'] ?? null;
        $ssid = data_get($entry, 'result.data.id');

        if (blank($contactId) || blank($ssid)) {
            return;
        }

        $ssidWritten = $this->hubSpotService->updateContactAmlSsid((string) $contactId, (string) $ssid);
        $responseWritten = $this->hubSpotService->updateContactAmlResponse((string) $contactId, $entry['result'] ?? null);

        $this->logService->forGroup($groupId)->webhook('HubSpot: contact aml written', [
            'contactId' => $contactId,
            'ssid' => $ssid,
            'outcome' => data_get($entry, 'result.included.0.attributes.outcome'),
            // The update methods log their own failures and return empty.
            'ssidWritten' => filled($ssidWritten),
            'responseWritten' => filled($responseWritten),
        ]);
    }

    /**
     * Stamp the date the AML searches were created onto the deal.
     */
    protected function writeUkIndividualRequestDateToDeal(string $dealId, array $aml, ?string $groupId): void
    {
        $createdAt = collect($aml)
            ->map(fn (array $entry) => data_get($entry, 'result.data.meta.created_at'))
            ->filter()
            ->first();

        if (blank($createdAt)) {
            return;
        }

        $date = Carbon::parse($createdAt);

        $response = $this->hubSpotService->updateUkIndividualRequestDate($dealId, $date);

        $this->logService->forGroup($groupId)->webhook('HubSpot: deal aml request date written', [
            'dealId' => $dealId,
            'createdAt' => $createdAt,
            'ukIndividualRequestDate' => $date->utc()->toDateString(),
            // updateUkIndividualRequestDate() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    /**
     * Write a SmartDoc search id onto the contact it was created for.
     */
    protected function writeSmartDocSsidToContact(string $ssid, ?string $contactId, ?string $groupId): void
    {
        if (blank($contactId)) {
            return;
        }

        $response = $this->hubSpotService->updateContactSmartDocSsid($contactId, $ssid);

        $this->logService->forGroup($groupId)->webhook('HubSpot: contact smartdoc ssid written', [
            'contactId' => $contactId,
            'ssid' => $ssid,
            // updateContactSmartDocSsid() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    /**
     * Write a failed SmartDoc creation onto the contact it was attempted for.
     *
     * @param  array  $entry  one runSmartDocSearch() outcome
     */
    protected function writeSmartDocErrorsToContact(array $entry, ?string $groupId): void
    {
        $contactId = $entry['contactId'] ?? null;
        $errors = $entry['errors'] ?? null;

        // A company owner search has no contact, and an entry skipped for
        // missing fields never reached SmartSearch to be answered.
        if (blank($contactId) || blank($errors)) {
            return;
        }

        $response = $this->hubSpotService->updateContactSmartDocResponse($contactId, ['errors' => $errors]);

        $this->logService->forGroup($groupId)->webhook('HubSpot: contact smartdoc errors written', [
            'contactId' => $contactId,
            'status' => $entry['status'] ?? null,
            'error' => $entry['error'] ?? null,
            'errors' => $errors,
            // updateContactSmartDocResponse() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    /**
     * Write the SmartDoc search status back onto the deal in HubSpot.
     */
    protected function writeSmartDocStatusToDeal(string $dealId, string $ssid, ?WebhookDetailStatus $status, ?Carbon $date, ?string $groupId, ?string $contactId = null): void
    {
        $value = ($status ?? WebhookDetailStatus::Pending)->value;

        $response = $this->hubSpotService->updateSmartDocStatus($dealId, $ssid, $value, $date, $contactId);

        $this->logService->forGroup($groupId)->webhook('HubSpot: deal smartdoc status written', [
            'dealId' => $dealId,
            'ssid' => $ssid,
            'hubspotContactId' => $contactId,
            'smartdocStatus' => $value,
            // updateSmartDocStatus() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    /**
     * Write the SmartDoc search ids back onto the deal in HubSpot.
     *
     * @param  array<int, string>  $ssids
     */
    protected function writeSmartDocSsidsToDeal(string $dealId, array $ssids, ?string $groupId): void
    {
        $value = implode(',', array_unique($ssids));

        $response = $this->hubSpotService->updateSmartDocSsid($dealId, $value);

        $this->logService->forGroup($groupId)->webhook('HubSpot: deal smartdoc ssid written', [
            'dealId' => $dealId,
            'smartdocSsid' => $value,
            // updateSmartDocSsid() logs its own failure and returns empty.
            'written' => filled($response),
        ]);
    }

    /**
     * Ask SmartSearch to call us back when a SmartDoc search completes.
     */
    protected function registerSmartDocWebhook(string $ssid, ?string $groupId): void
    {
        try {
            $response = $this->smartDocService->createWebhook($ssid);

            $this->logService->forGroup($groupId)->webhook('SmartSearch: smartdoc webhook registered', [
                'ssid' => $ssid,
                'response' => $response,
            ]);
        } catch (SmartSearchException $e) {
            Log::warning('SmartDoc webhook registration failed.', [
                'ssid' => $ssid,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            $this->logService->forGroup($groupId)->webhook('SmartSearch: smartdoc webhook registration failed', [
                'ssid' => $ssid,
                'status' => $e->status,
                'error' => $e->getMessage(),
                'errors' => $e->errors,
            ]);
        }
    }

    protected function searchPropertiesOn(array $deal, string $trigger): array
    {
        $properties = $deal['properties'] ?? [];

        return collect(self::SEARCH_PROPERTIES[$trigger] ?? [])
            ->mapWithKeys(fn (string $property) => [$property => $properties[$property] ?? null])
            ->filter(fn ($value) => filled($value))
            ->all();
    }

    /**
     * Run a SmartSearch AML search for each contact on the deal.
     *
     * Never throws: a contact that cannot be searched is recorded alongside
     * the ones that could, so one bad contact does not lose the rest.
     */
    protected function runAmlSearches(array $contacts): array
    {
        $results = [];

        foreach ($contacts as $contact) {
            $properties = $contact['properties'] ?? [];

            // search on aml service
            $results[] = $this->runAmlSearch(
                [
                    'title' => $properties['honorifictitle'] ?? null,
                    'first_name' => $properties['firstname'] ?? null,
                    'last_name' => $properties['lastname'] ?? null,
                    'address1' => $properties['address'] ?? null,
                    'city' => $properties['city'] ?? null,
                    'postcode' => $properties['zip'] ?? null,
                ],
                [
                    'contactId' => $contact['id'] ?? null,
                    // Both forms: an association can carry more than one label,
                    // and the payload is what the callback reads back later.
                    'label' => $contact['label'] ?? null,
                    'labels' => $contact['labels'] ?? [],
                ],
                self::AML_REQUIRED_FIELDS,
            );
        }

        return $results;
    }

    /**
     * Run a single AML search for the company owner
     */
    protected function runCompanyOwnerAmlSearch(array $company): array
    {
        $owner = $company['owner'] ?? [];
        $properties = $company['properties'] ?? [];

        // there is no owner to search and nothing else records why.
        $this->logService->webhook('HubSpot: company owner aml search', [
            'companyId' => $company['id'] ?? null,
            'ownerId' => $owner['id'] ?? null,
            'owner' => $owner,
            'properties' => $properties,
            'searched' => filled($owner),
        ]);

        if (blank($owner)) {
            return [];
        }

        $result = $this->runAmlSearch(
            [
                // Owner records carry no salutation, and the AML endpoint
                // rejects a blank title, so it is fixed here.
                'title' => self::OWNER_TITLE,
                'first_name' => $owner['firstName'] ?? null,
                'last_name' => $owner['lastName'] ?? null,
                // The owner is searched at their own address where they have
                // one; the company's is the fallback, since an owner record
                // often carries nothing but a name and an email.
                'address1' => $this->firstFilled($owner['address'] ?? null, $properties['address'] ?? null),
                'city' => $this->firstFilled($owner['city'] ?? null, $properties['city'] ?? null),
                'postcode' => $this->firstFilled($owner['zip'] ?? null, $properties['zip'] ?? null),
                'country' => $this->firstFilled($owner['country'] ?? null, $properties['country'] ?? null),
            ],
            [
                'source' => 'company owner',
                'companyId' => $company['id'] ?? null,
                'ownerId' => $owner['id'] ?? null,
            ],
            self::AML_REQUIRED_FIELDS,
        );

        return [$result];
    }

    /**
     * The first value that is actually set, treating HubSpot's empty strings
     * the same as its nulls so an unset property still falls through.
     */
    protected function firstFilled(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The contacts that go through AML: the ones whose association with the
     * company labels them a director or a PSC.
     *
     * @param  array<int, array>  $contacts
     * @return array<int, array>
     */
    protected function amlContacts(array $contacts): array
    {
        return collect($contacts)
            ->filter(fn (array $contact) => $this->hasAmlLabel($contact['labels'] ?? []))
            ->values()
            ->all();
    }

    /**
     * Whether any of an association's labels marks the contact as an AML
     * subject. Matched on a lowercased substring so the wording of the label in
     * HubSpot can vary without the check having to be kept in step with it.
     *
     * @param  array<int, string>  $labels
     */
    protected function hasAmlLabel(array $labels): bool
    {
        return collect($labels)->contains(
            fn (string $label) => collect(self::AML_LABELS)->contains(
                fn (string $wanted) => str_contains(Str::lower($label), $wanted),
            ),
        );
    }

    /**
     * Create a SmartDoc verification for each contact on the deal.
     */
    protected function runSmartDocSearches(array $contacts): array
    {
        $results = [];

        foreach ($contacts as $contact) {
            $properties = $contact['properties'] ?? [];

            $results[] = $this->runSmartDocSearch(
                $this->smartDocData($properties),
                [
                    'contactId' => $contact['id'] ?? null,
                    'label' => $contact['label'] ?? null,
                    'labels' => $contact['labels'] ?? [],
                    'email' => $properties['email'] ?? null,
                    'phone' => $properties['phone'] ?? null,
                ],
            );
        }

        return $results;
    }

    /**
     * Create a SmartDoc verification for the company owner, for deals that
     * have no associated contacts.
     */
    protected function runCompanyOwnerSmartDocSearch(array $company): array
    {
        $owner = $company['owner'] ?? [];

        if (blank($owner)) {
            return [];
        }

        $data = $this->smartDocData($company['properties'] ?? []);

        // The owner supplies the name, the company the address.
        $data['first_name'] = $owner['firstName'] ?? null;
        $data['last_name'] = $owner['lastName'] ?? null;

        return [
            $this->runSmartDocSearch($data, [
                'source' => 'company owner',
                'companyId' => $company['id'] ?? null,
                'ownerId' => $owner['id'] ?? null,
                'email' => $owner['email'] ?? null,
                'phone' => $company['properties']['phone'] ?? null,
            ]),
        ];
    }

    /**
     * Map HubSpot properties onto the SmartDoc payload fields.
     */
    protected function smartDocData(array $properties): array
    {
        return [
            'title' => $properties['honorifictitle'] ?? null,
            'first_name' => $properties['firstname'] ?? null,
            'middle_name' => null,
            'last_name' => $properties['lastname'] ?? null,
            'date_of_birth' => HubSpotProperty::date($properties['dob_date_of_birth'] ?? null),
            'sex' => HubSpotProperty::sex($properties['gender'] ?? null),
            'building' => $properties['address'] ?? null,
            'street_1' => $properties['address'] ?? null,
            'town' => $properties['city'] ?? null,
            'region' => $properties['state'] ?? null,
            'postcode' => $properties['zip'] ?? null,
            'country' => $properties['country'] ?? 'GBR',
        ];
    }

    /**
     * Run one SmartDoc creation and describe the outcome.
     *
     * Never throws, for the same reason as the AML searches.
     */
    protected function runSmartDocSearch(array $data, array $meta): array
    {
        $missing = array_values(array_filter(
            self::SMARTDOC_REQUIRED_FIELDS,
            fn (string $field) => blank($data[$field] ?? null),
        ));

        if (filled($missing)) {
            return [...$meta, 'skipped' => 'missing required smartdoc fields', 'missing' => $missing];
        }

        try {
            return [...$meta, 'result' => $this->smartDocService->create($data)];
        } catch (SmartSearchException $e) {
            Log::warning('SmartDoc creation failed for HubSpot subject.', [
                ...$meta,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return [...$meta, 'error' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors];
        }
    }

    /**
     * Run a fraud check for each contact and describe every outcome.
     *
     * @return array<int, array> one entry per contact, in the order given
     */
    protected function runFraudChecks(array $contacts): array
    {
        $results = [];

        foreach ($contacts as $contact) {
            $properties = $contact['properties'] ?? [];

            $results[] = $this->runFraudCheck(
                [
                    'title' => $properties['honorifictitle'] ?? null,
                    'first_name' => $properties['firstname'] ?? null,
                    'last_name' => $properties['lastname'] ?? null,
                    'address1' => $properties['address'] ?? null,
                    'city' => $properties['city'] ?? null,
                    'region' => $properties['state'] ?? null,
                    'postcode' => $properties['zip'] ?? null,
                    'country' => $properties['country'] ?? 'GBR',
                    'dob' => HubSpotProperty::date($properties['dob_date_of_birth'] ?? null),
                    'mobile' => HubSpotProperty::phone(
                        $this->firstFilled(
                            $properties['mobilephone'] ?? null,
                            $properties['phone'] ?? null,
                        ),
                        $properties['country'] ?? 'GBR',
                    ),
                    'email' => $properties['email'] ?? null,
                ],
                [
                    'contactId' => $contact['id'] ?? null,
                    'label' => $contact['label'] ?? null,
                    'labels' => $contact['labels'] ?? [],
                ],
            );
        }

        return $results;
    }

    /**
     * Run one fraud check and describe the outcome.
     */
    protected function runFraudCheck(array $data, array $meta): array
    {
        $missing = array_values(array_filter(
            self::FRAUD_CHECK_REQUIRED_FIELDS,
            fn (string $field) => blank($data[$field] ?? null),
        ));

        if (filled($missing)) {
            return [...$meta, 'skipped' => 'missing required fraud check fields', 'missing' => $missing];
        }

        try {
            return [...$meta, 'result' => $this->fraudCheckService->create($data)];
        } catch (SmartSearchException $e) {
            Log::warning('Fraud check failed for HubSpot subject.', [
                ...$meta,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return [...$meta, 'error' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors];
        }
    }

    /**
     * Hold each fraud check id against the search it ran alongside, and write
     * the response it came back with onto the contact it was run for.
     */
    protected function recordFraudCheckDetails(string $dealId, array $fraudChecks, ?string $groupId): void
    {
        foreach ($fraudChecks as $entry) {
            $contactId = $entry['contactId'] ?? null;

            if (blank($contactId)) {
                continue;
            }

            $fraudCheckId = data_get($entry, 'result.data.id');

            // Only a check that came back with an id has anything to hold onto.
            $saved = filled($fraudCheckId)
                ? $this->webhookDetails->saveFraudCheckId($dealId, (string) $contactId, (string) $fraudCheckId)
                : 0;

            $response = $entry['result'] ?? Arr::only($entry, ['skipped', 'missing', 'error', 'errors', 'status']);

            $written = $this->hubSpotService->updateContactFraudCheckResponse(
                (string) $contactId,
                $response,
            );

            $status = $this->fraudCheckStatus($entry);

            $statusWritten = filled($status)
                ? $this->hubSpotService->updateContactFraudStatus((string) $contactId, $status)
                : [];

            $this->logService->forGroup($groupId)->webhook('HubSpot: contact fraud check written', [
                'dealId' => $dealId,
                'contactId' => $contactId,
                'fraudCheckId' => $fraudCheckId,
                'status' => $status,
                'statusWritten' => filled($statusWritten),
                'detailsSaved' => $saved,
                'responseWritten' => filled($written),
            ]);
        }
    }

    protected function fraudCheckStatus(array $entry): ?string
    {
        $outcome = collect(data_get($entry, 'result.included', []))
            ->firstWhere('type', 'fraud-check-result');

        return data_get($outcome, 'attributes.outcome')
            ?? match (true) {
                filled($entry['skipped'] ?? null) => 'skipped',
                filled($entry['error'] ?? null) => 'failed',
                filled(data_get($entry, 'result.data.id')) => data_get($entry, 'result.data.meta.status'),
                default => null,
            };
    }

    /**
     * Run one SmartSearch AML search and describe the outcome.
     *
     * @param  array  $meta  identifying fields merged into the result
     * @param  array  $required  fields that must be present to search
     */
    protected function runAmlSearch(array $data, array $meta, array $required): array
    {
        $missing = array_values(array_filter(
            $required,
            fn (string $field) => blank($data[$field] ?? null),
        ));

        if (filled($missing)) {
            return [...$meta, 'skipped' => 'missing required contact fields', 'missing' => $missing];
        }

        try {
            return [...$meta, 'result' => $this->amlService->search($data)];
        } catch (SmartSearchException $e) {
            Log::warning('SmartSearch AML search failed for HubSpot subject.', [
                ...$meta,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return [...$meta, 'error' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors];
        }
    }

    /**
     * Fetch a deal's properties from the HubSpot API.
     */
    protected function fetchDeal(string $dealId): array
    {
        $client = $this->hubSpotAuth->client('fetch deal');

        if (blank($client)) {
            return [];
        }

        $response = $client->get("/crm/v3/objects/deals/{$dealId}", [
            'properties' => 'dealname,amount,dealstage,pipeline,closedate,createdate,hubspot_owner_id,dealtype,'
                .implode(',', array_merge(...array_values(self::SEARCH_PROPERTIES))),
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot deal.', [
                'dealId' => $dealId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $properties = $response->json('properties', []);

        return [
            'id' => $response->json('id'),
            'properties' => $properties,
            'owner' => $this->fetchOwner($properties['hubspot_owner_id'] ?? null),
        ];
    }

    /**
     * Fetch the contacts associated with a deal from the HubSpot API.
     */
    protected function fetchDealContacts(string $dealId): array
    {
        return $this->fetchAssociatedContacts('deals', $dealId);
    }

    /**
     * Fetch the contacts associated with a company from the HubSpot API.
     *
     * These are the fallback subjects for a deal that has no contacts of its
     * own but is associated with a company that does.
     */
    protected function fetchCompanyContacts(string $companyId): array
    {
        return $this->fetchAssociatedContacts('companies', $companyId);
    }

    /**
     * Fetch the contacts associated with a HubSpot object, with the properties
     * both searches read.
     *
     * @param  string  $objectType  the plural object type, e.g. deals, companies
     */
    protected function fetchAssociatedContacts(string $objectType, string $objectId): array
    {
        $client = $this->hubSpotAuth->client("fetch {$objectType} contacts");

        if (blank($client)) {
            return [];
        }

        $associations = $client->get("/crm/v4/objects/{$objectType}/{$objectId}/associations/contacts");

        if ($associations->failed()) {
            Log::warning('Failed to fetch HubSpot contact associations.', [
                'objectType' => $objectType,
                'objectId' => $objectId,
                'status' => $associations->status(),
                'body' => $associations->json(),
            ]);

            return [];
        }

        $labels = collect($associations->json('results', []))
            ->filter(fn (array $association) => filled($association['toObjectId'] ?? null))
            ->mapWithKeys(fn (array $association) => [
                (string) $association['toObjectId'] => collect($association['associationTypes'] ?? [])
                    ->pluck('label')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);

        if ($labels->isEmpty()) {
            return [];
        }

        $contactIds = $labels->keys();

        $response = $client->post('/crm/v3/objects/contacts/batch/read', [
            // honorifictitle/address/city/zip feed the AML search;
            // dob_date_of_birth/gender feed the SmartDoc verification.
            'properties' => ['firstname', 'lastname', 'email', 'phone', 'mobilephone', 'company', 'lifecyclestage', 'honorifictitle', 'address', 'city', 'zip', 'state', 'country', 'dob_date_of_birth', 'gender'],
            'inputs' => $contactIds->map(fn ($id) => ['id' => (string) $id])->all(),
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot contacts.', [
                'objectType' => $objectType,
                'objectId' => $objectId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return collect($response->json('results', []))
            ->map(fn (array $contact) => [
                'id' => $contact['id'] ?? null,
                'properties' => $contact['properties'] ?? [],
                // Unlabelled associations are the HubSpot default one, which
                // carries no label at all, so this is empty rather than absent.
                'labels' => $labels->get((string) ($contact['id'] ?? ''), []),
                'label' => collect($labels->get((string) ($contact['id'] ?? ''), []))->first(),
            ])
            ->all();
    }

    /**
     * Fetch the company associated with a deal, along with its owner.
     */
    protected function fetchDealCompany(string $dealId): array
    {
        $client = $this->hubSpotAuth->client('fetch deal company');

        if (blank($client)) {
            return [];
        }

        $associations = $client->get("/crm/v4/objects/deals/{$dealId}/associations/companies");

        if ($associations->failed()) {
            Log::warning('Failed to fetch HubSpot deal company associations.', [
                'dealId' => $dealId,
                'status' => $associations->status(),
                'body' => $associations->json(),
            ]);

            return [];
        }

        $companyId = collect($associations->json('results', []))
            ->pluck('toObjectId')
            ->filter()
            ->first();

        if (blank($companyId)) {
            return [];
        }

        $response = $client->get("/crm/v3/objects/companies/{$companyId}", [
            'properties' => 'name,domain,industry,phone,address,city,state,zip,country,hubspot_owner_id',
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot company for deal.', [
                'dealId' => $dealId,
                'companyId' => $companyId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        $properties = $response->json('properties', []);

        return [
            'id' => $response->json('id'),
            'properties' => $properties,
            'owner' => $this->fetchOwner($properties['hubspot_owner_id'] ?? null),
        ];
    }

    /**
     * Fetch a HubSpot owner (user) record by id.
     */
    protected function fetchOwner(?string $ownerId): array
    {
        if (blank($ownerId)) {
            return [];
        }

        $client = $this->hubSpotAuth->client('fetch owner');

        if (blank($client)) {
            return [];
        }

        $response = $client->get("/crm/v3/owners/{$ownerId}");

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot owner.', [
                'ownerId' => $ownerId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return [
            'id' => $response->json('id'),
            'email' => $response->json('email'),
            'firstName' => $response->json('firstName'),
            'lastName' => $response->json('lastName'),
            'userId' => $response->json('userId'),
            'address' => $response->json('address'),
            'city' => $response->json('city'),
            'zip' => $response->json('zip'),
            'country' => $response->json('country'),
        ];
    }
}
