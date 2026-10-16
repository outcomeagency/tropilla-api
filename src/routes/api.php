<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
| API v1 — único contrato entre tropilla-api, tropilla-next y tropilla-flutter.
| Cada dominio agrega su grupo acá (Establecimientos, Hacienda, Sync, ...).
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', MeController::class)->name('me');
    });
});
