<?php

namespace App\Repositories;

use App\Enums\LogType;
use App\Models\Log;
use App\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LogRepository implements LogRepositoryInterface
{
    /**
     * Persist a new log entry.
     */
    public function create(LogType $type, ?string $message = null, ?array $payload = null, ?string $logGroupId = null): Log
    {
        return Log::create([
            'log_group_id' => $logGroupId,
            'type' => $type,
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    /**
     * Paginate logs, newest first, optionally filtered by type.
     */
    public function paginate(?LogType $type = null, int $perPage = 25): LengthAwarePaginator
    {
        return Log::query()
            ->when($type, fn ($query, $type) => $query->where('type', $type))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
