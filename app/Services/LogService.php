<?php

namespace App\Services;

use App\Enums\LogType;
use App\Models\Log;
use App\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Support\Str;

class LogService
{
    /**
     * Shared group id for all logs created during this process.
     */
    protected string $logGroupId;

    public function __construct(
        protected LogRepositoryInterface $logs,
    ) {
        $this->logGroupId = (string) Str::uuid();
    }

    /**
     * Log against an existing group rather than this process's own.
     *
     * Returns a copy, so logging into another group never moves the group the
     * rest of the process is writing to. A blank group id changes nothing.
     */
    public function forGroup(?string $logGroupId): self
    {
        if (blank($logGroupId)) {
            return $this;
        }

        $clone = clone $this;
        $clone->logGroupId = $logGroupId;

        return $clone;
    }

    /**
     * Create a log entry.
     */
    public function create(LogType $type, ?string $message = null, ?array $payload = null): Log
    {
        return $this->logs->create($type, $message, $payload, $this->logGroupId);
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
