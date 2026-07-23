<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartSearchSearch extends Model
{
    protected $fillable = [
        'search_id',
        'type',
        'status',
        'client_ref',
        'payload',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
        ];
    }
}
