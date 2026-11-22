<?php

use Subdomains\Http\Controllers\SubdomainController;
use Illuminate\Support\Facades\Route;

Route::post('/create', [SubdomainController::class, 'create'])->name('create');
