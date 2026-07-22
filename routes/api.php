<?php

use App\Http\Controllers\Webhook\HubSpotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/hubspot', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');
