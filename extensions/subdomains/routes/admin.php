<?php

use Subdomains\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/cfconfig', [SettingsController::class, 'get'])->name('cfconfig.get');
Route::post('/cfconfig', [SettingsController::class, 'save'])->name('cfconfig.save');
