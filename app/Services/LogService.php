<?php

namespace App\Services;

use App\Enums\LogType;
use App\Models\Log;
use App\Repositories\Contracts\LogRepositoryInterface;

class LogService
{
    public function __construct(
        protected LogRepositoryInterface $logs,
    ) {}

    /**
     * Create a log entry.
     */
    public function create(LogType $type, ?string $message = null, ?array $payload = null): Log
    {
        return $this->logs->create($type, $message, $payload);
    }

    /**
     * Create a webhook log entry.
     */
    public function webhook(?string $message = null, ?array $payload = null): Log
    {
        return $this->create(LogType::Webhook, $message, $payload);
    }

    /**
     * Create an api log entry.
     */
    public function api(?string $message = null, ?array $payload = null): Log
    {
        return $this->create(LogType::Api, $message, $payload);
    }
}
