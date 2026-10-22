<?php

namespace App\Services\SmartSearch;

class TripleCheckService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Create a TripleCheck search for an individual.
     */
    public function create(array $data): array
    {
        return $this->client->post(
            '/v3/triplecheck/searches',
            [
                'data' => [
                    'type' => 'triplecheck',
                    'attributes' => [
                        'client_reference' => $data['client_reference'] ?? null,
                        'date_of_birth' => $data['dob'] ?? null,
                        'sex' => $data['sex'] ?? null,
                        'document_types' => [
                            'passport','driving-licence','id-card'
                        ],
                        'scan_type' =>'advanced',
                        'name' => [
                            'title' => $data['title'] ?? null,
                            'first' => $data['first_name'] ?? null,
                            'middle' => $data['middle_name'] ?? null,
                            'last' => $data['last_name'] ?? null,
                        ],
                        'contacts' => [
                            'mobile' => self::mobile($data['mobile'] ?? null),
                            'email' => $data['email'] ?? null,
                        ],
                        'addresses' => [[
                            'flat' => $data['flat'] ?? null,
                            'building' => $data['address1'] ?? null,
                            'street_1' => $data['street_1'] ?? null,
                            'street_2' => $data['street_2'] ?? null,
                            'town' => $data['city'] ?? null,
                            'region' => $data['region'] ?? null,
                            'postcode' => $data['postcode'] ?? null,
                            'duration' => $data['address_duration'] ?? 1,
                        ]],
                        //'bank' => $data['bank'] ?? [],
                        //'documents' => $data['documents'] ?? [],
                    ],
                   /* 'relationships' => [
                        'parent' => [
                            'data' => [
                                'type' => 'group',
                                'id' => config('services.smartsearch.group_id'),
                            ],
                        ],
                        'subject' => [
                            'data' => [
                                'type' => 'search-subject',
                                'id' => $data['search_subject_id'] ?? null,
                            ],
                        ],
                        'uk_individual_config' => [
                            'data' => [
                                'type' => 'search-config',
                                'id' => config('services.smartsearch.uk_individual_config_id'),
                            ],
                        ],
                        'fraud_check_config' => [
                            'data' => [
                                'type' => 'search-config',
                                'id' => config('services.smartsearch.fraud_check_config_id'),
                            ],
                        ],
                        'smart_doc_config' => [
                            'data' => [
                                'type' => 'search-config',
                                'id' => config('services.smartsearch.smart_doc_config_id'),
                            ],
                        ],
                    ],*/
                ],
            ],
        )->json();
    }

    /**
     * Retrieve a TripleCheck search by id.
     */
    public function get(string $tripleCheckId): array
    {
        return $this->client->get(
            "/v3/triplecheck/searches/{$tripleCheckId}"
        )->json();
    }

    /**
     * Normalise a mobile number to the format the API expects.
     */
    protected static function mobile(mixed $value): string
    {
        $number = preg_replace('/[^\d+]/', '', (string) $value) ?? '';

        return str_starts_with($number, '+')
            ? '+'.str_replace('+', '', $number)
            : str_replace('+', '', $number);
    }
}
