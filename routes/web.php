<?php

use App\Http\Controllers\LogController;
use App\Http\Middleware\PreventIndexing;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(PreventIndexing::class)->group(function () {
    Route::get('/logs/{token}', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{token}/storage', [LogController::class, 'storage'])->name('logs.storage');
});
