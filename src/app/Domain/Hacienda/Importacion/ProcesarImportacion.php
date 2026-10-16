<?php

namespace App\Domain\Hacienda\Importacion;

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carga inicial de stock por planilla (TPA-21, modelo-datos §12): validar (fila por fila, con errores y advertencias)
 * → corregir/descartar filas → confirmar = **un evento `hacienda.alta` por fila** (`motivo: importacion`,
 * `origen: importacion`). `animal_id` y `evento_id` se preasignan al validar: confirmar es idempotente.
 */
final class ProcesarImportacion
{
    public function __construct(private readonly RegistrarEvento $registrar) {}

    public function validar(string $importacionId): void
    {
        $imp = DB::table('importaciones')->where('id', $importacionId)->first();
        if ($imp === null || ! in_array($imp->estado, ['subida', 'validando'], true)) {
            return;
        }
        DB::table('importaciones')->where('id', $imp->id)->update(['estado' => 'validando', 'updated_at' => now()]);
        try {
            $filas = LectorPlanilla::leer((string) GuardarArchivo::contenido($imp->archivo_sha256), $imp->formato);
        } catch (Throwable $e) {
            report($e);
            DB::table('importaciones')->where('id', $imp->id)->update(['estado' => 'con_errores', 'error' => 'No se pudo leer la planilla.', 'updated_at' => now()]);

            return;
        }
        $mapeo = json_decode($imp->mapeo, true) ?: [];
        $validador = new ValidadorFila($imp->establecimiento_id, $imp->especie_default);

        DB::transaction(function () use ($imp, $filas, $mapeo, $validador) {
            DB::table('importacion_filas')->where('importacion_id', $imp->id)->delete();
            foreach ($filas as $i => $fila) {
                foreach ($mapeo as $origen => $destino) {
                    $clave = Str::slug((string) $origen, '_');
                    if (array_key_exists($clave, $fila)) {
                        $fila[$destino] = $fila[$clave];
                    }
                }
                $r = $validador->validar($fila, $i + 1);
                DB::table('importacion_filas')->insert([
                    'importacion_id' => $imp->id, 'numero' => $i + 1, 'datos' => json_encode($r['datos'], JSON_UNESCAPED_UNICODE),
                    'errores' => json_encode($r['errores'], JSON_UNESCAPED_UNICODE), 'advertencias' => json_encode($r['advertencias'], JSON_UNESCAPED_UNICODE),
                    'accion' => 'importar', 'animal_id' => (string) Str::uuid7(), 'evento_id' => (string) Str::uuid7(),
                ]);
            }
            $this->recontar($imp->id);
        });
    }

    /** Corregir (`datos` = columnas de la planilla) o descartar una fila; se revalida en el acto. */
    public function corregirFila(object $imp, int $numero, ?array $datos, ?string $accion): object
    {
        if (! in_array($imp->estado, ['validada', 'con_errores'], true)) {
            throw ApiException::validacion(__('La importación no se puede editar en este estado.'), ['estado' => [$imp->estado]]);
        }
        $fila = DB::table('importacion_filas')->where('importacion_id', $imp->id)->where('numero', $numero)->first() ?? throw ApiException::noEncontrado();
        $cambios = [];
        if ($accion !== null) {
            $cambios['accion'] = $accion;
        }
        if ($datos !== null) {
            $r = (new ValidadorFila($imp->establecimiento_id, $imp->especie_default))->validar(array_map('strval', $datos), $numero);
            $cambios += ['datos' => json_encode($r['datos'], JSON_UNESCAPED_UNICODE), 'errores' => json_encode($r['errores'], JSON_UNESCAPED_UNICODE),
                'advertencias' => json_encode($r['advertencias'], JSON_UNESCAPED_UNICODE)];
        }
        DB::table('importacion_filas')->where('importacion_id', $imp->id)->where('numero', $numero)->update($cambios);
        $this->recontar($imp->id);

        return DB::table('importacion_filas')->where('importacion_id', $imp->id)->where('numero', $numero)->first();
    }

    public function confirmar(string $importacionId, User $usuario): void
    {
        $imp = DB::table('importaciones')->where('id', $importacionId)->first();
        if ($imp === null || $imp->estado === 'confirmada') {
            return;
        }
        $est = Establecimiento::query()->findOrFail($imp->establecimiento_id);
        $hoy = now()->setTimezone($est->zona_horaria)->toDateString();
        $filas = DB::table('importacion_filas')->where('importacion_id', $imp->id)->where('accion', 'importar')
            ->whereRaw("errores = '[]'::jsonb")->orderBy('numero')->get();

        foreach ($filas as $f) {
            if (DB::table('eventos')->where('id', $f->evento_id)->exists()) {
                continue; // ya importada (reintento)
            }
            $animal = ['animal_id' => $f->animal_id, ...json_decode($f->datos, true)];
            $in = json_decode(json_encode([
                'id' => $f->evento_id, 'tipo' => 'hacienda.alta',
                'sujetos' => array_filter(['tipo' => 'animales', 'animal_ids' => [$f->animal_id], 'lote_id' => $animal['lote_id'] ?? null]),
                'payload' => ['motivo' => 'importacion', 'importacion_id' => $imp->id, 'animales' => [$animal]],
                'ocurrido_en' => $hoy,
            ]), false);
            $sobre = ArmarSobreWeb::alta($est, $usuario, $in);
            $sobre->origen = 'importacion';
            try {
                $this->registrar->handle($est, $usuario, $sobre, ['canal' => 'web', 'confirmar_advertencias' => true]);
            } catch (EventoRechazado|ApiException $e) {
                DB::table('importacion_filas')->where('importacion_id', $imp->id)->where('numero', $f->numero)
                    ->update(['errores' => json_encode([['campo' => 'fila', 'mensaje' => $e->getMessage()]], JSON_UNESCAPED_UNICODE)]);
            }
        }
        $this->recontar($imp->id);
        DB::table('importaciones')->where('id', $imp->id)->update(['estado' => 'confirmada', 'confirmada_en' => now(), 'updated_at' => now()]);
    }

    private function recontar(string $id): void
    {
        $total = DB::table('importacion_filas')->where('importacion_id', $id)->count();
        $errores = DB::table('importacion_filas')->where('importacion_id', $id)->where('accion', 'importar')->whereRaw("errores <> '[]'::jsonb")->count();
        $validas = DB::table('importacion_filas')->where('importacion_id', $id)->where('accion', 'importar')->whereRaw("errores = '[]'::jsonb")->count();
        $estado = DB::table('importaciones')->where('id', $id)->value('estado');
        DB::table('importaciones')->where('id', $id)->update([
            'filas_total' => $total, 'filas_error' => $errores, 'filas_validas' => $validas, 'updated_at' => now(),
            'estado' => in_array($estado, ['confirmada', 'cancelada'], true) ? $estado : ($errores > 0 ? 'con_errores' : 'validada'),
        ]);
    }
}
