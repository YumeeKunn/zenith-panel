<?php

use Startupchanger\Http\Controllers\StartupController;
use Illuminate\Support\Facades\Route;

// /api/client/servers/{server}/extensions/startupchanger/...
Route::get('/', [StartupController::class, 'show'])->name('show');
Route::post('/', [StartupController::class, 'update'])->name('update');
