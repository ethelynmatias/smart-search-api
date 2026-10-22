<?php

use App\Http\Controllers\Webhooks\HubSpotWebhookController;
use App\Http\Controllers\Webhooks\SmartSearchWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/hubspot/event', HubSpotWebhookController::class)
    ->name('webhooks.hubspot');

Route::post('/hubspot/contact-smartdoc-notification', [SmartSearchWebhookController::class, 'handleContactSmartdocNotification'])
    ->name('webhooks.hubspot.contact-smartdoc-notification');

Route::post('/hubspot/contact-triple-check-notification', [SmartSearchWebhookController::class, 'handleContactTripleCheckNotification'])
    ->name('webhooks.hubspot.contact-triple-check-notification');

Route::post('/smartsearch/search', [SmartSearchWebhookController::class, 'handle'])
    ->name('webhooks.smartsearch');

Route::post('/smartsearch/triplecheck', [SmartSearchWebhookController::class, 'handleTripleCheck'])
    ->name('webhooks.smartsearch.triplecheck');
