<?php

namespace App\DTOs\SmartSearch;

class AMLData
{
    public const DOCUMENT_TYPES = ['passport', 'driving_licence', 'national_id_card'];

    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $dateOfBirth,
        public readonly string $country,
        public readonly AddressData $address,
        public readonly ?string $title = null,
        public readonly ?string $middleName = null,
        public readonly ?string $sex = null,
        public readonly array $documentTypes = self::DOCUMENT_TYPES,
        public readonly ?string $clientRef = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            dateOfBirth: $data['date_of_birth'],
            country: $data['country'],
            address: AddressData::fromArray($data['address']),
            title: $data['title'] ?? null,
            middleName: $data['middle_name'] ?? null,
            sex: $data['sex'] ?? null,
            documentTypes: $data['document_types'] ?? self::DOCUMENT_TYPES,
            clientRef: $data['client_ref'] ?? null,
        );
    }

    public function toPayload(): array
    {
        return [
            'data' => [
                'type' => 'ukindividual',
                'attributes' => array_filter([
                    'client_ref' => $this->clientRef,
                    'documents' => $this->documentTypes,
                    'name' => array_filter([
                        'title' => $this->title,
                        'first' => $this->firstName,
                        'middle' => $this->middleName,
                        'last' => $this->lastName,
                    ]),
                    'date_of_birth' => $this->dateOfBirth,
                    'sex' => $this->sex,
                    'country' => $this->country,
                    'address' => $this->address->toArray(),
                ]),
            ],
        ];
    }
}
