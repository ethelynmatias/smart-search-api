<?php

use App\Http\Controllers\Webhooks\HubSpotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/hubspot/event', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');
