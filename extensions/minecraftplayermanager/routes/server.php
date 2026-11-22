<?php

use Minecraftplayermanager\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

Route::get('/online', [PlayerController::class, 'online'])->name('online');
Route::post('/command', [PlayerController::class, 'command'])->name('command');
