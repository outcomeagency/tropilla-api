<?php

namespace App\Domain\Documentos\Borradores\Actions;

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Borradores;
use App\Domain\Documentos\Borradores\Extraccion\ExtraccionFallida;
use App\Domain\Documentos\Borradores\Extraccion\Extractor;
use App\Domain\Documentos\Borradores\ExtraerBorradorJob;
use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `en_cola` → `extrayendo` → `para_revisar` | `fallido`. Cada extracción exitosa queda **append-only** en
 * `borrador_extracciones` (proveedor, modelo, esquema, páginas, salida cruda, campos y advertencias) y reemplaza la
 * proyección de campos del borrador con todo en `pendiente`: nada se da por bueno sin revisión humana.
 * Errores transitorios del proveedor: hasta 3 intentos con espera creciente; después `fallido` (se puede reintentar a mano).
 */
final class ExtraerBorrador
{
    public const MAX_INTENTOS = 3;

    public function __construct(private readonly Extractor $extractor) {}

    public function handle(string $id): void
    {
        $b = DB::transaction(function () use ($id) {
            $b = DB::table('borradores_documento')->where('id', $id)->lockForUpdate()->first();
            if ($b === null || $b->estado !== 'en_cola') {
                return null;
            }
            DB::table('borradores_documento')->where('id', $id)->update(['estado' => 'extrayendo', 'intentos' => $b->intentos + 1, 'updated_at' => now()]);
            $b->intentos++;

            return $b;
        });
        if ($b === null) {
            return;
        }

        $familia = Familias::get($b->familia);
        $shas = Borradores::paginas($b);
        $mimes = DB::table('archivos')->whereIn('sha256', $shas)->pluck('mime', 'sha256');
        $inicio = hrtime(true);
        try {
            $paginas = array_map(function ($sha) use ($mimes) {
                $bytes = GuardarArchivo::contenido($sha) ?? throw new ExtraccionFallida(__('Una página no está disponible en el almacenamiento.'), true);

                return ['mime' => (string) ($mimes[$sha] ?? 'image/jpeg'), 'bytes' => $bytes];
            }, $shas);
            $r = $this->extractor->extraer($paginas, $familia->instrucciones(), $familia->esquemaSalida());
        } catch (ExtraccionFallida $e) {
            $this->fallo($b, $e);

            return;
        }

        $norm = $familia->normalizar($r->salida);
        $est = Establecimiento::query()->findOrFail($b->establecimiento_id);
        $adv = $familia->advertencias($est, $norm['campos'], $r->salida);
        DB::transaction(function () use ($b, $r, $norm, $adv, $shas, $inicio) {
            $version = (int) DB::table('borrador_extracciones')->where('borrador_id', $b->id)->max('version') + 1;
            DB::table('borrador_extracciones')->insert([
                'borrador_id' => $b->id, 'version' => $version, 'esquema' => $norm['esquema'], 'proveedor' => $r->proveedor, 'modelo' => $r->modelo,
                'paginas' => json_encode($shas), 'salida' => json_encode($r->salida, JSON_UNESCAPED_UNICODE), 'campos' => json_encode($norm['campos'], JSON_UNESCAPED_UNICODE),
                'advertencias' => json_encode($adv, JSON_UNESCAPED_UNICODE), 'tokens_entrada' => $r->tokensEntrada, 'tokens_salida' => $r->tokensSalida,
                'duracion_ms' => (int) ((hrtime(true) - $inicio) / 1e6),
            ]);
            DB::table('borradores_documento')->where('id', $b->id)->update([
                'estado' => 'para_revisar', 'esquema' => $norm['esquema'], 'campos' => json_encode($norm['campos'], JSON_UNESCAPED_UNICODE),
                'advertencias' => json_encode($adv, JSON_UNESCAPED_UNICODE), 'extraccion_vigente' => $version, 'error' => null,
                'row_version' => DB::raw('row_version + 1'), 'updated_at' => now(),
            ]);
        });
    }

    private function fallo(object $b, ExtraccionFallida $e): void
    {
        Log::warning('borrador: extracción fallida', ['borrador_id' => $b->id, 'intento' => $b->intentos, 'error' => $e->getMessage()]);
        $reintenta = $e->reintentable && $b->intentos < self::MAX_INTENTOS;
        DB::table('borradores_documento')->where('id', $b->id)->update([
            'estado' => $reintenta ? 'en_cola' : 'fallido', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now(),
        ]);
        if ($reintenta) {
            ExtraerBorradorJob::dispatch($b->id)->delay(now()->addSeconds(30 * $b->intentos));
        }
    }
}
