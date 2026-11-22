<?php

use Mcplugins\Http\Controllers\PluginController;
use Illuminate\Support\Facades\Route;

Route::get('/search', [PluginController::class, 'search'])->name('search');
Route::get('/versions/{slug}', [PluginController::class, 'versions'])->name('versions');
Route::post('/install', [PluginController::class, 'install'])->name('install');
