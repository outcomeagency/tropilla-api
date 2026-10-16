<?php

namespace App\Domain\Sistema\Actions;

use App\Domain\Sistema\Data\EstadoDelSistema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Chequea las dependencias críticas de la API: PostgreSQL (+ PostGIS) y Redis.
 * Sin efectos de dominio, por eso no abre transacción.
 */
final class VerificarSalud
{
    public function handle(): EstadoDelSistema
    {
        return new EstadoDelSistema([
            'database' => $this->chequear(fn () => DB::select('select 1')),
            'postgis' => $this->chequear(fn () => DB::scalar('select postgis_lib_version()')),
            'redis' => $this->chequear(fn () => Redis::connection()->ping()),
        ]);
    }

    /**
     * @return array{ok: bool, latencia_ms: float, detalle: string|null}
     */
    private function chequear(callable $chequeo): array
    {
        $inicio = hrtime(true);

        try {
            $resultado = $chequeo();
            $ok = true;
            $detalle = is_string($resultado) ? $resultado : null;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
            // El detalle del error solo se expone con APP_DEBUG.
            $detalle = config('app.debug') ? $e->getMessage() : null;
        }

        return [
            'ok' => $ok,
            'latencia_ms' => round((hrtime(true) - $inicio) / 1e6, 2),
            'detalle' => $detalle,
        ];
    }
}
