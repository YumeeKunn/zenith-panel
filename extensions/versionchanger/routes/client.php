<?php

use Versionchanger\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

// /api/client/servers/{server}/extensions/versionchanger/...
Route::get('/versions', [VersionController::class, 'versions'])->name('versions');
Route::post('/switch', [VersionController::class, 'switchVersion'])->name('switch');
