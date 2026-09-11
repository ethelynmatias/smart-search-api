<?php

use App\Http\Controllers\LogController;
use App\Http\Controllers\SmartSearchController;
use App\Http\Controllers\StorageLogController;
use App\Http\Middleware\PreventIndexing;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(PreventIndexing::class)->group(function () {
    Route::get('/logs/{token}', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{token}/storage', [StorageLogController::class, 'index'])->name('logs.storage');
    Route::get('/smartsearch/documents/{token}', [SmartSearchController::class, 'documents'])->name('smartsearch.documents');
});
