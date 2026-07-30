<?php

namespace App\Services\SmartSearch;

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
                        'country' => $data['country'] ?? 'GBR',
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

    /**
     * Register a webhook SmartSearch calls back when a search completes.
     *
     * The callback url comes from config unless one is passed explicitly.
     */
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

    /**
     * Send a search subject the link to their SmartDoc verification.
     *
     * @param  string  $method  sms | email
     * @param  string  $value  the mobile number or email address to send to
     * @param  string|null  $redirectTo  where to send the subject once they finish
     */
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
                //'meta' => new stdClass,
            ],
        ])->json();
    }
}
