<?php

use Illuminate\Support\Facades\Route;
use ZenithMonitor\Http\Controllers\StatusController;

// Final URL: GET /api/client/extensions/zenith-monitor/status
Route::get('/status', StatusController::class)->name('status');
