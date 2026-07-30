<?php

namespace App\Enums;

enum WebhookDetailStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * Map a SmartSearch search status onto our own.
     *
     * An unrecognised status leaves the detail pending rather than guessing at
     * a terminal state, so the search is still treated as outstanding.
     */
    public static function fromSmartSearch(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'complete', 'completed' => self::Completed,
            'error', 'failed', 'failure' => self::Failed,
            'expired' => self::Expired,
            'processing', 'in_progress', 'running' => self::Processing,
            default => self::Pending,
        };
    }
}
