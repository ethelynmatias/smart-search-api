<?php

namespace App\DTOs\SmartSearch;

class SmartDocData
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $country,
        public readonly AddressData $address,
        public readonly ?string $dateOfBirth = null,
        public readonly ?string $title = null,
        public readonly ?string $middleName = null,
        public readonly ?string $sex = null,
        public readonly ?string $email = null,
        public readonly ?string $mobile = null,
        public readonly array $documentTypes = AMLData::DOCUMENT_TYPES,
        public readonly ?string $clientRef = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            country: $data['country'],
            address: AddressData::fromArray($data['address']),
            dateOfBirth: $data['date_of_birth'] ?? null,
            title: $data['title'] ?? null,
            middleName: $data['middle_name'] ?? null,
            sex: $data['sex'] ?? null,
            email: $data['email'] ?? null,
            mobile: $data['mobile'] ?? null,
            documentTypes: $data['document_types'] ?? AMLData::DOCUMENT_TYPES,
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
                    'email' => $this->email,
                    'mobile' => $this->mobile,
                    'address' => $this->address->toArray(),
                ]),
            ],
        ];
    }
}
