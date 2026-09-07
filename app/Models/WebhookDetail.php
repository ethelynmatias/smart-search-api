<?php

namespace App\Models;

use App\Enums\WebhookDetailStatus;
use Illuminate\Database\Eloquent\Model;

class WebhookDetail extends Model
{
    protected $fillable = [
        'group_id',
        'deal_id',
        'hubspot_contact_id',
        'ssid',
        'fraud_check_id',
        'search_subject_id',
        'type',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookDetailStatus::class,
            'payload' => 'array',
        ];
    }
}
