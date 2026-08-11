<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One HubSpot webhook event this app has seen, keyed on HubSpot's event id.
 *
 * The row is the idempotency key rather than a record for its own sake: it
 * exists so a redelivered event can be recognised and dropped.
 */
class HubSpotWebhookEvent extends Model
{
    // Set explicitly: the class name snake cases to hub_spot_webhook_events.
    protected $table = 'hubspot_webhook_events';

    protected $fillable = [
        'event_id',
        'object_id',
        'subscription_type',
        'property_name',
    ];
}
