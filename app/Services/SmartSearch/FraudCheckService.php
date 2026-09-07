<?php

namespace App\Services\SmartSearch;

use App\Services\SmartSearch\Exceptions\SmartSearchException;

class FraudCheckService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

     public function create(array $data): array
    {
        return $this->client->post(
            '/v3/fraud-checks',
            [
                'data' => [
                    'type' => 'fraud-check',
                    'attributes' => [
                        'name' => [
                            'title' => $data['title'] ?? null,
                            'first' => $data['first_name'],
                            'last' => $data['last_name'],
                        ],

                        'addresses' => [
                            [
                                'building' => $data['address1'],
                                'town' => $data['city'],
                                'postcode' => $data['postcode'],
                                'duration' => 1,
                            ],
                        ],

                        'date_of_birth' => $data['dob'] ?? null,
                    ],
                ],
            ]
        )->json();
    }

    public function get(string $fraudCheckId): array
    {
        return $this->client->get(
            "/v3/fraud-checks/{$fraudCheckId}"
        )->json();
    }
}
