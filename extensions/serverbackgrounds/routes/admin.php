<?php

use Serverbackgrounds\Http\Controllers\AdminBackgroundController;
use Illuminate\Support\Facades\Route;

Route::get('/backgrounds', [AdminBackgroundController::class, 'index'])->name('backgrounds.index');
Route::post('/backgrounds', [AdminBackgroundController::class, 'set'])->name('backgrounds.set');
Route::delete('/backgrounds', [AdminBackgroundController::class, 'remove'])->name('backgrounds.remove');
