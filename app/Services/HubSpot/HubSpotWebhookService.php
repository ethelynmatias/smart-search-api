<?php

namespace App\Services\HubSpot;

use App\Enums\WebhookDetailStatus;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;
use App\Services\LogService;
use App\Services\SmartSearch\AmlService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\SmartDocService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * Title the company owner is searched under. HubSpot owners are users
     * rather than contacts, so they carry no salutation to read, and the AML
     * endpoint will not accept the title blank.
     */
    protected const OWNER_TITLE = 'Mr';

    /**
     * Deal properties each checkbox writes its search results to, keyed by the
     * checkbox that triggers them. Any of a checkbox's own properties holding a
     * value means that search has run already; the other checkbox is
     * unaffected, so both can still run on the same deal.
     */
    protected const SEARCH_PROPERTIES = [
        'ss_smartdoc' => ['smartdoc_ssid', 'smartdoc_status'],
        'ss_individual_uk' => ['smartsearch_uk_individual_ssid'],
    ];

    public function __construct(
        protected LogService $logService,
        protected AmlService $amlService,
        protected SmartDocService $smartDocService,
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

        if ($this->isActionable($event)) {
            $this->logService->webhook("HubSpot: {$type}", $event);
        }

        match ($type) {
            'deal.propertyChange' => $this->handleDealPropertyChange($event),
            default => Log::debug('Unhandled HubSpot webhook event', ['type' => $type]),
        };
    }

    /**
     * Whether an event is one this app acts on, and so worth a log line.
     *
     * Only deal.propertyChange is filtered: the searches are triggered by the
     * checkbox properties rather than the deal stage, so a change to any other
     * property, or a checkbox being cleared, is nothing to record.
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

        $contacts = $this->fetchDealContacts((string) $dealId);
        // $company = $this->fetchDealCompany((string) $dealId);

        /*if (blank($company['owner'] ?? [])) {
            $company['owner'] = $deal['owner'] ?? [];
        }*/

        $log = $this->logService->webhook("HubSpot: deal {$property} contacts", [
            'dealId' => $dealId,
            // 'propertyName' => $property,
            // 'propertyValue' => $value,
            'deal' => $deal,
            'contacts' => $contacts,
            // 'company' => $company,
        ]);

        // A deal with no contacts runs nothing for now; the company owner
        // fallback is parked rather than dropped.
        // : $this->runCompanyOwnerAmlSearch($company))
        $aml = $property === 'ss_individual_uk' && filled($contacts)
            ? $this->runAmlSearches($contacts)
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

        // SmartDoc belongs to its own checkbox and runs off the same subjects.
        // : $this->runCompanyOwnerSmartDocSearch($company))
        $smartDoc = $property === 'ss_smartdoc' && filled($contacts)
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
     * A deal with several subjects creates several searches, and the deal holds
     * one property, so the ids go on comma separated rather than the last one
     * quietly overwriting the rest.
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
     *
     * Never throws: the detail is already stored as pending, so a registration
     * that fails leaves a record to chase rather than losing the search.
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

    /**
     * The search properties already filled in on a deal for the checkbox that
     * triggered this run, keyed by property. ss_smartdoc looks at the smartdoc
     * properties, ss_individual_uk at the UK individual ssid, so one search
     * having run does not block the other.
     *
     * Empty for a deal that has not been searched, which is also what a deal we
     * could not fetch looks like — better to search twice than not at all.
     *
     * @return array<string, string>
     */
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

            $results[] = $this->runAmlSearch(
                [
                    'title' => $properties['salutation'] ?? null,
                    'first_name' => $properties['firstname'] ?? null,
                    'last_name' => $properties['lastname'] ?? null,
                    'address1' => $properties['address'] ?? null,
                    'city' => $properties['city'] ?? null,
                    'postcode' => $properties['zip'] ?? null,
                ],
                ['contactId' => $contact['id'] ?? null],
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
                    // Carried through so the completion callback can notify the
                    // subject without fetching the contact from HubSpot again.
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
     *
     * Every key SmartDocService reads is present, so a property HubSpot does
     * not hold is sent as null rather than raising an undefined key warning.
     */
    protected function smartDocData(array $properties): array
    {
        return [
            'title' => $properties['salutation'] ?? null,
            'first_name' => $properties['firstname'] ?? null,
            'middle_name' => null,
            'last_name' => $properties['lastname'] ?? null,
            'date_of_birth' => $this->normaliseDate($properties['date_of_birth'] ?? null),
            'sex' => $this->normaliseSex($properties['gender'] ?? null),
            'building' => $properties['address'] ?? null,
            'street_1' => $properties['address'] ?? null,
            'town' => $properties['city'] ?? null,
            'region' => $properties['state'] ?? null,
            'postcode' => $properties['zip'] ?? null,
            'country' => $properties['country'] ?? 'GBR',
        ];
    }

    /**
     * Normalise a HubSpot date property to the Y-m-d SmartDoc expects.
     *
     * Date properties come back as either an ISO date or epoch milliseconds
     * depending on how the property was written.
     */
    protected function normaliseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestampMs((int) $value)->format('Y-m-d');
            }

            return $this->normaliseSlashedDate(trim((string) $value))
                ?? Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalise a slash separated date, working out which part is the day.
     *
     * Carbon reads slashed dates as m/d/Y, so 17/11/2004 would throw and a
     * genuine 11/17/2004 would be read correctly by luck alone. A part above 12
     * can only be the day, which settles most dates; where both parts could be
     * either, d/m/Y wins, as HubSpot holds these in UK format.
     *
     * Returns null for anything that is not a slashed date, leaving the caller
     * to parse it as before.
     */
    protected function normaliseSlashedDate(string $value): ?string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches)) {
            return null;
        }

        [, $first, $second, $year] = array_map('intval', $matches);

        // A day/month pair the other way round: 11/17/2004.
        [$day, $month] = $second > 12 ? [$second, $first] : [$first, $second];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->format('Y-m-d');
    }

    /**
     * Normalise a HubSpot gender property to the SmartDoc sex values.
     *
     * Anything that is not recognisably male or female is dropped rather
     * than guessed at, so the search is skipped instead of sent wrong.
     */
    protected function normaliseSex(mixed $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
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
     * Run one SmartSearch AML search and describe the outcome.
     *
     * Never throws: a subject that cannot be searched is recorded alongside
     * the ones that could, so one bad subject does not lose the rest.
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
            // The smartdoc/smartsearch properties are what we wrote on a previous
            // run; they are read back to tell an already searched deal apart.
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
            // Resolved here rather than left as an id, so a deal with no company
            // still has a named owner to fall back on as the search subject.
            'owner' => $this->fetchOwner($properties['hubspot_owner_id'] ?? null),
        ];
    }

    /**
     * Fetch the contacts associated with a deal from the HubSpot API.
     */
    protected function fetchDealContacts(string $dealId): array
    {
        $client = $this->hubSpotAuth->client('fetch deal contacts');

        if (blank($client)) {
            return [];
        }

        $associations = $client->get("/crm/v4/objects/deals/{$dealId}/associations/contacts");

        if ($associations->failed()) {
            Log::warning('Failed to fetch HubSpot deal contact associations.', [
                'dealId' => $dealId,
                'status' => $associations->status(),
                'body' => $associations->json(),
            ]);

            return [];
        }

        $contactIds = collect($associations->json('results', []))
            ->pluck('toObjectId')
            ->filter()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        $response = $client->post('/crm/v3/objects/contacts/batch/read', [
            // salutation/address/city/zip feed the AML search;
            // date_of_birth/gender feed the SmartDoc verification.
            'properties' => ['firstname', 'lastname', 'email', 'phone', 'company', 'lifecyclestage', 'salutation', 'address', 'city', 'zip', 'state', 'country', 'date_of_birth', 'gender'],
            'inputs' => $contactIds->map(fn ($id) => ['id' => (string) $id])->all(),
        ]);

        if ($response->failed()) {
            Log::warning('Failed to fetch HubSpot contacts for deal.', [
                'dealId' => $dealId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return collect($response->json('results', []))
            ->map(fn (array $contact) => [
                'id' => $contact['id'] ?? null,
                'properties' => $contact['properties'] ?? [],
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
            // Owners are HubSpot users and usually carry no address of their
            // own; passed through so that an owner that does is searched at it
            // rather than at the company's.
            'address' => $response->json('address'),
            'city' => $response->json('city'),
            'zip' => $response->json('zip'),
            'country' => $response->json('country'),
        ];
    }
}
