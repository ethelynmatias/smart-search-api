<?php

use App\Http\Controllers\SmartSearchController;
use App\Http\Controllers\Webhook\HubSpotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/smartsearch/uk-individual', [SmartSearchController::class, 'ukIndividual'])
    ->name('smartsearch.uk-individual');

Route::post('/hubspot/event', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');
