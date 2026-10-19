<?php

namespace App\Services\SmartSearch;

use App\Support\HubSpotProperty;

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
                    'type' => 'triple-check',
                    'attributes' => [
                        'name' => [
                            'title' => $data['title'],
                            'first' => $data['first_name'],
                            'middle' => $data['middle_name'] ?? null,
                            'last' => $data['last_name'],
                        ],
                        'contacts' => [
                            'mobile' => self::mobile($data['mobile']),
                            'email' => $data['email'] ?? null,
                        ],
                        'address' => [
                            'flat' => $data['flat'] ?? null,
                            'building' => $data['address1'],
                            'street_1' => $data['street_1'] ?? null,
                            'street_2' => $data['street_2'] ?? null,
                            'town' => $data['city'],
                            'region' => $data['region'] ?? null,
                            'postcode' => $data['postcode'],
                            'country' => HubSpotProperty::country($data['country'] ?? null),
                        ],
                        'date_of_birth' => $data['dob'] ?? null,
                    ],
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
