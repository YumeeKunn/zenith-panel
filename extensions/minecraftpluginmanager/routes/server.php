<?php

use Minecraftpluginmanager\Http\Controllers\ModpackController;
use Mcplugins\Http\Controllers\PluginController;
use Illuminate\Support\Facades\Route;

Route::get('/search', [ModpackController::class, 'search'])->name('search');
Route::post('/install', [PluginController::class, 'install'])->name('install');
