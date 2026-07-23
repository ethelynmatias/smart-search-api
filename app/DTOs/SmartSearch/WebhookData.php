<?php

namespace App\DTOs\SmartSearch;

class WebhookData
{
    public function __construct(
        public readonly ?string $event,
        public readonly ?string $searchId,
        public readonly ?string $status,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        $attributes = $payload['data']['attributes'] ?? $payload;

        return new self(
            event: $attributes['event'] ?? $attributes['type'] ?? null,
            searchId: $attributes['search_id'] ?? $payload['data']['id'] ?? null,
            status: $attributes['status'] ?? null,
            raw: $payload,
        );
    }

    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'searchId' => $this->searchId,
            'status' => $this->status,
            'raw' => $this->raw,
        ];
    }
}
