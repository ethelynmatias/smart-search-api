<?php

namespace App\Models;

use App\Enums\LogType;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $fillable = [
        'log_group_id',
        'type',
        'message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => LogType::class,
            'payload' => 'array',
        ];
    }
}
