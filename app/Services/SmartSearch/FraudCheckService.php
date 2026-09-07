<?php

namespace App\Services\SmartSearch;

class FraudCheckService
{
    public function __construct(
        protected SmartSearchClient $client,
    ) {}

    public function create(array $data): array
    {
        return $this->client->post(
            '/v3/fraudcheck/searches',
            [
                'data' => [
                    'type' => 'fraud-check',
                    'attributes' => [
                        'name' => [
                            // Required by the API, not optional.
                            'title' => $data['title'],
                            'first' => $data['first_name'],
                            'middle' => $data['middle_name'] ?? null,
                            'last' => $data['last_name'],
                        ],

                        // An object, not a list, and the mobile is required —
                        // the email is the optional half of the pair.
                        'contacts' => [
                            'mobile' => self::mobile($data['mobile']),
                            'email' => $data['email'] ?? null,
                        ],

                        // Singular, unlike the AML endpoint's 'addresses' array,
                        // which this endpoint rejects as an unexpected field.
                        'address' => [
                            'flat' => $data['flat'] ?? null,
                            'building' => $data['address1'],
                            'street_1' => $data['street_1'] ?? null,
                            'street_2' => $data['street_2'] ?? null,
                            'town' => $data['city'],
                            'region' => $data['region'] ?? null,
                            'postcode' => $data['postcode'],
                            'country' => $data['country'] ?? 'GBR',
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
            "/v3/fraudcheck/searches/{$fraudCheckId}"
        )->json();
    }

    /**
     * Normalise a mobile number to the format the API expects.
     */
    protected static function mobile(mixed $value): string
    {
        $number = preg_replace('/[^\d+]/', '', (string) $value) ?? '';

        // A plus is a country code only at the front; anywhere else it is noise.
        return str_starts_with($number, '+')
            ? '+'.str_replace('+', '', $number)
            : str_replace('+', '', $number);
    }
}
