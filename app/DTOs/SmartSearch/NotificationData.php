<?php

namespace App\DTOs\SmartSearch;

class NotificationData
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $type,
        public readonly array $attributes = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            type: $data['type'] ?? null,
            attributes: $data['attributes'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'attributes' => $this->attributes,
        ];
    }
}
