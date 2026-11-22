<?php

use Simplefavicons\Http\Controllers\FaviconController;
use Illuminate\Support\Facades\Route;

Route::get('/favicon', [FaviconController::class, 'get'])->name('favicon.get');
Route::post('/favicon', [FaviconController::class, 'set'])->name('favicon.set');
