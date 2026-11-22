<?php

use Startupchanger\Http\Controllers\PresetController;
use Illuminate\Support\Facades\Route;

// /api/admin/extensions/startupchanger/...
Route::get('/presets', [PresetController::class, 'index'])->name('presets');
Route::post('/presets', [PresetController::class, 'save'])->name('presets.save');
Route::delete('/presets/{key}', [PresetController::class, 'delete'])->name('presets.delete');
