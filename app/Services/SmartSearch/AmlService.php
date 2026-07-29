<?php

namespace App\Services\SmartSearch;

use App\Services\SmartSearch\Exceptions\SmartSearchException;

class AmlService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    /**
     * Run a UK individual AML search.
     *
     * @throws SmartSearchException
     */
    public function search(array $data): array
    {
        return $this->client->post(
            '/v3/ukindividual/searches',
            [
                'data' => [
                    'type' => 'uk-individual',
                    'attributes' => [
                        'name' => [
                            // Required by the API, not optional.
                            'title' => $data['title'] ?? null,
                            'first' => $data['first_name'],
                            'last' => $data['last_name'],
                        ],

                        // Plural, and the endpoint only accepts these three subfields.
                        'addresses' => [
                            [
                                'building' => $data['address1'],
                                'town' => $data['city'],
                                'postcode' => $data['postcode'],
                                'duration'=> 1
                            ],
                        ],

                        // Optional but recommended
                        'date_of_birth' => $data['dob'] ?? null,
                    ],
                ],
            ]
        )->json();
    }
}
