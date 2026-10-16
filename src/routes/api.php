<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\DispositivoController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| API v1 — único contrato entre tropilla-api, tropilla-next y tropilla-flutter.
| Contrato formal: docs/openapi-v1.yaml. Cada dominio agrega su grupo acá.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // Auth (TPA-9)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('register');
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('email/verificar', [AuthController::class, 'verificarEmail'])->name('email.verificar');
            Route::post('password/olvide', [AuthController::class, 'olvide'])->name('password.olvide');
            Route::post('password/restablecer', [AuthController::class, 'restablecer'])->name('password.restablecer');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::patch('me', [AuthController::class, 'actualizarMe'])->name('me.actualizar');
            Route::post('email/reenviar', [AuthController::class, 'reenviarVerificacion'])->middleware('throttle:auth')->name('email.reenviar');
            Route::put('dispositivos/{dispositivo}', [DispositivoController::class, 'update'])->name('dispositivos.update');
            Route::post('dispositivos/{dispositivo}/revocar', [DispositivoController::class, 'revocar'])->name('dispositivos.revocar');
        });
    });
});
