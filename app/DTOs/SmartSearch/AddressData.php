<?php

namespace App\DTOs\SmartSearch;

class AddressData
{
    public function __construct(
        public readonly string $address1,
        public readonly string $town,
        public readonly string $postcode,
        public readonly ?string $flat = null,
        public readonly ?string $building = null,
        public readonly ?string $address2 = null,
        public readonly ?string $region = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            address1: $data['address_1'],
            town: $data['town'],
            postcode: $data['postcode'],
            flat: $data['flat'] ?? null,
            building: $data['building'] ?? null,
            address2: $data['address_2'] ?? null,
            region: $data['region'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'flat' => $this->flat,
            'building' => $this->building,
            'line_1' => $this->address1,
            'line_2' => $this->address2,
            'town' => $this->town,
            'region' => $this->region,
            'postcode' => $this->postcode,
        ]);
    }
}
