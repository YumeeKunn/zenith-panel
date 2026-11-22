<?php

use ZenithMonitor\Http\Controllers\AlertsController;
use ZenithMonitor\Http\Controllers\GraphController;
use Illuminate\Support\Facades\Route;

// /api/admin/extensions/zenith-monitor/...
Route::get('/settings', [AlertsController::class, 'index'])->name('settings');
Route::post('/settings', [AlertsController::class, 'update'])->name('settings.update');
Route::post('/test', [AlertsController::class, 'test'])->name('test');
Route::get('/nodes/{node:id}/graphs', [GraphController::class, 'node'])->name('graphs.node');
Route::get('/servers/{server:admin_identifier}/graphs', [GraphController::class, 'server'])->name('graphs.server');
Route::get('/nodes/{node:id}/server-usage', [GraphController::class, 'nodeUsage'])->name('usage.node');
