<?php

namespace App\Services\SmartSearch;

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
                    'scan_type' => $data['scan_type'] ?? 'expert',
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
}
