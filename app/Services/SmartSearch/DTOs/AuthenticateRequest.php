<?php

namespace App\Services\SmartSearch\DTOs;

class AuthenticateRequest
{
    public function __construct(
        public readonly string $appId,
        public readonly string $appSecret,
    ) {}

    public function toPayload(): array
    {
        return [
            'data' => [
                'type' => 'app-token',
                'attributes' => [
                    'app_id' => $this->appId,
                    'app_secret' => $this->appSecret,
                ],
            ],
        ];
    }
}
