<?php

namespace App\Services\SmartSearch\DTOs;

class UKIndividualRequest
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $dateOfBirth,
        public readonly array $address,
        public readonly ?string $title = null,
        public readonly ?string $middleName = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            dateOfBirth: $data['date_of_birth'],
            address: $data['address'],
            title: $data['title'] ?? null,
            middleName: $data['middle_name'] ?? null,
        );
    }

    public function toPayload(): array
    {
        return [
            'data' => [
                'type' => 'ukindividual',
                'attributes' => array_filter([
                    'name' => array_filter([
                        'title' => $this->title,
                        'first' => $this->firstName,
                        'middle' => $this->middleName,
                        'last' => $this->lastName,
                    ]),
                    'date_of_birth' => $this->dateOfBirth,
                    'address' => $this->address,
                ]),
            ],
        ];
    }
}
