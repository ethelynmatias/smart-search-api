<?php

namespace App\Services\SmartSearch;

use App\Support\HubSpotProperty;
use Illuminate\Http\Client\Response;
use stdClass;

class SmartDocService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Create a SmartDoc verification search.
     */
    public function create(array $data): array
    {
        return $this->client->post('/v3/smartdoc/searches', [
            'data' => [
                'type' => 'smartdoc',
                'attributes' => [
                    'client_reference' => $data['client_reference'] ?? null,
                    'rule_adjustments' => null,
                    'date_of_birth' => $data['date_of_birth'],
                    'sex' => $data['sex'],
                    'scan_type' => 'advanced',
                    'status' => 'complete',

                    'document_types' => [
                        'passport',
                    ],

                    'name' => [
                        'title' => $data['title'],
                        'first' => $data['first_name'],
                        'middle' => $data['middle_name'] ?? null,
                        'last' => $data['last_name'],
                    ],

                    'address' => [
                        'flat' => $data['flat'] ?? null,
                        'building' => $data['building'],
                        'street_1' => $data['street_1'],
                        'street_2' => $data['street_2'] ?? null,
                        'town' => $data['town'],
                        'region' => $data['region'],
                        'postcode' => $data['postcode'],
                        'country' => HubSpotProperty::country($data['country'] ?? null),
                    ],

                    'redirect_to' => null,
                ],

                /*'relationships' => [
                    'parent' => [
                        'data' => [
                            'type' => 'group',
                            'id' => config('services.smartsearch.group_id'),
                        ],
                    ],

                    'subject' => [
                        'data' => [
                            'type' => 'search-subject',
                            'id' => $data['search_subject_id'],
                        ],
                    ],

                    'config' => [
                        'data' => [
                            'type' => 'search-config',
                            'id' => config('services.smartsearch.search_config_id'),
                        ],
                    ],
                ],*/
            ],
        ])->json();
    }

    public function createWebhook(string $searchId, ?string $callbackUrl = null): array
    {
        $callbackUrl ??= config('services.smartsearch.webhook_url') ?: route('webhooks.smartsearch');

        return $this->client->post(
            "/v3/searches/{$searchId}/webhooks",
            [
                'data' => [
                    'type' => 'search-webhook',
                    'attributes' => [
                        'url' => $callbackUrl,
                    ],
                    'relationships' => [
                        'search' => [
                            'data' => [
                                'type' => 'search',
                                'id' => $searchId,
                            ],
                        ],
                    ],
                ],
            ]
        )->json();
    }

    public function sendNotification(
        string $searchSubjectId,
        string $method,
        string $value,
        ?string $redirectTo = null,
    ): array {
        $attributes = [
            'method' => $method,
            'value' => $value,
        ];

        if (filled($redirectTo)) {
            $attributes['redirect_to'] = $redirectTo;
        }

        return $this->client->post('/v3/notifications', [
            'data' => [
                'type' => 'subject-notification',
                'attributes' => $attributes,
                'relationships' => [
                    'subject' => [
                        'data' => [
                            'type' => 'search-subject',
                            'id' => $searchSubjectId,
                        ],
                    ],
                ],
                // An empty object, not an empty array, so it encodes as {}.
                // 'meta' => new stdClass,
            ],
        ])->json();
    }

    public function findUkBusiness(string $crn, string $businessType = 'ltd'): array
    {
        return $this->client->post('/v3/ukbusiness/find', [
            'data' => [
                'type' => 'find-uk-business',
                'attributes' => [
                    'business_type' => $businessType,
                    'crn' => $crn,
                ],
            ],
        ])->json();
    }

    /* Fetch all documents */
    public function listAllDocuments(
        string $subject,
        string $cabinet,
        string $category,
        string $documentType,
        string $retrieved,
        ?string $include = 'types,categories',
    ): array {
        //$size = min($size, 25);

        $query = [
            'filter[subject]' => $subject,
            'filter[cabinet]' => $cabinet,
            'filter[category]' => $category,
            'filter[document-type]' => $documentType,
            'filter[retrieved]' => $retrieved,
            //'page[number]' => $page,
            //'page[size]' => $size,
        ];

        if (filled($include)) {
            $query['include'] = $include;
        }

        return $this->client
            ->get('/v3/document', $query)
            ->json();
    }

    public function listCategories(): array
    {
        return $this->client
            ->get('/v3/document/categories')
            ->json();
    }

    public function listDocumentTypes(): array
    {
        
        return $this->client
        ->get('/v3/document/types')
        ->json();
    }

    public function getDocument(string $documentId): array
    {
        /*return $this->client
            ->get("v3/document-request/searches/{$documentId}")
            ->json();*/

        return $this->client
            ->get("v3/document/{$documentId}")
            ->json();
    }

    public function getPdfLink(string $documentId): array
    {
        return $this->client
            ->get("/v3/document/{$documentId}/pdf-link")
            ->json();
    }

    /**
     * Download a document's PDF as a binary file.
     */
    public function getPdf(string $documentId): Response
    {
        return $this->client->download("/v3/document/{$documentId}/pdf");
    }
}
