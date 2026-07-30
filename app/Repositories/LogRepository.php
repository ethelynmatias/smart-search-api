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
     * Paginate logs, newest first, optionally filtered by type and/or group.
     *
     * Without a group filter, only the latest record of each group is shown,
     * with a `group_count` of how many records the group holds.
     */
    public function paginate(?LogType $type = null, ?string $logGroupId = null, int $perPage = 25, ?string $search = null): LengthAwarePaginator
    {
        return Log::query()
            ->select('logs.*')
            ->when($type, fn ($query, $type) => $query->where('type', $type))
            ->when($logGroupId, fn ($query, $logGroupId) => $query->where('log_group_id', $logGroupId))
            // An ssid appears in the payload under several keys — ssid on our own
            // writes, data.id in a SmartSearch response — so the search runs over
            // the whole payload rather than one path.
            ->when($search, fn ($query, $search) => $query->where(
                'payload',
                'like',
                '%'.addcslashes($search, '%_\\').'%',
            ))
            // A search wants every matching line, the same as a group view does.
            ->when(! $logGroupId && ! $search, function ($query) {
                $query
                    ->where(function ($query) {
                        $query
                            ->whereNull('log_group_id')
                            ->orWhereIn('id', Log::selectRaw('MAX(id)')->whereNotNull('log_group_id')->groupBy('log_group_id'));
                    })
                    ->addSelect([
                        'group_count' => Log::from('logs as grouped')
                            ->selectRaw('COUNT(*)')
                            ->whereColumn('grouped.log_group_id', 'logs.log_group_id'),
                    ]);
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
