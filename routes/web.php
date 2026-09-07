<?php

use App\Http\Controllers\LogController;
use App\Http\Controllers\StorageLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/logs/{token}', [LogController::class, 'index'])->name('logs.index');
Route::get('/logs/{token}/storage', [StorageLogController::class, 'index'])->name('logs.storage');
