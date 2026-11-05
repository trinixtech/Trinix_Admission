<?php

use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\System\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Load-balancer health check: database, Redis and storage.
Route::get('/up', [HealthController::class, 'up'])->withoutMiddleware(['web'])->name('health');

// Private document files: short-lived signed link, signed-in user, permission checked again, every opening logged.
Route::get('/files/{version}', [DocumentController::class, 'file'])
    ->middleware(['auth', 'signed', 'account.ready'])->name('files.show');

// Everything else is the Vue single-page app.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|sanctum/|horizon|files/|up$|install$).*$')->name('spa');
