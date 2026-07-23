<?php

use App\Http\Controllers\SmartSearchController;
use App\Http\Controllers\Webhook\HubSpotWebhookController;
use App\Http\Controllers\Webhooks\SmartSearchWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/smartsearch/aml', [SmartSearchController::class, 'aml'])
    ->name('smartsearch.aml');

Route::post('/smartsearch/smartdoc', [SmartSearchController::class, 'smartDoc'])
    ->name('smartsearch.smartdoc');

Route::post('/smartsearch/event', SmartSearchWebhookController::class)
    ->name('webhooks.smartsearch');

Route::post('/hubspot/event', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');
