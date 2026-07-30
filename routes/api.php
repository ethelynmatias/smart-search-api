<?php

use App\Http\Controllers\Webhooks\HubSpotWebhookController;
use App\Http\Controllers\Webhooks\SmartSearchWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/hubspot/event', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');

Route::post('/smartsearch/search', [SmartSearchWebhookController::class, 'handle'])
    ->name('webhooks.smartsearch');
