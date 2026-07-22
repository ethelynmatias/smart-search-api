<?php

namespace App\Repositories\Contracts;

use App\Enums\LogType;
use App\Models\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LogRepositoryInterface
{
    /**
     * Persist a new log entry.
     */
    public function create(LogType $type, ?string $message = null, ?array $payload = null): Log;

    /**
     * Paginate logs, newest first, optionally filtered by type.
     */
    public function paginate(?LogType $type = null, int $perPage = 25): LengthAwarePaginator;
}
