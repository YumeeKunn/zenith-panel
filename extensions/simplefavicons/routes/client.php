<?php

use Illuminate\Support\Facades\Route;
use Simplefavicons\Http\Controllers\StatusController;

// Final URL: GET /api/client/extensions/simplefavicons/status
Route::get('/status', StatusController::class)->name('status');
