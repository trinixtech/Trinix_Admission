<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Installation wizard: no sessions, cookies or CSRF (there may be no key or database yet). 404 once installed.
Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'step']);
