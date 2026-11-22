<?php

use Serverbackgrounds\Http\Controllers\BackgroundController;
use Illuminate\Support\Facades\Route;

// /api/client/servers/{server}/extensions/serverbackgrounds/...
Route::get('/', [BackgroundController::class, 'get'])->name('get');
