<?php

namespace App\DTOs\SmartSearch;

class NotificationData
{
    public const METHOD_SMS = 'sms';

    public const METHOD_EMAIL = 'email';

    public function __construct(
        public readonly string $ssid,
        public readonly string $searchSubjectId,
        public readonly string $method = self::METHOD_SMS,
        public readonly ?string $mobile = null,
        public readonly ?string $email = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'data' => [
                'type' => 'notification',
                'attributes' => array_filter([
                    'ssid' => $this->ssid,
                    'search_subject_id' => $this->searchSubjectId,
                    'method' => $this->method,
                    'mobile' => $this->mobile,
                    'email' => $this->email,
                ]),
            ],
        ];
    }
}
