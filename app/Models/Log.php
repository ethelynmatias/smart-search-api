<?php

namespace App\Models;

use App\Enums\LogType;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $fillable = [
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
