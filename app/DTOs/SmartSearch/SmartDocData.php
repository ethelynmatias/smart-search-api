<?php

namespace App\DTOs\SmartSearch;

class SmartDocData
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $dateOfBirth,
        public readonly string $email,
        public readonly ?string $title = null,
        public readonly ?string $middleName = null,
        public readonly ?string $mobile = null,
        public readonly ?string $clientRef = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            dateOfBirth: $data['date_of_birth'],
            email: $data['email'],
            title: $data['title'] ?? null,
            middleName: $data['middle_name'] ?? null,
            mobile: $data['mobile'] ?? null,
            clientRef: $data['client_ref'] ?? null,
        );
    }

    public function toPayload(): array
    {
        return [
            'data' => [
                'type' => 'smartdoc',
                'attributes' => array_filter([
                    'client_ref' => $this->clientRef,
                    'name' => array_filter([
                        'title' => $this->title,
                        'first' => $this->firstName,
                        'middle' => $this->middleName,
                        'last' => $this->lastName,
                    ]),
                    'date_of_birth' => $this->dateOfBirth,
                    'email' => $this->email,
                    'mobile' => $this->mobile,
                ]),
            ],
        ];
    }
}
