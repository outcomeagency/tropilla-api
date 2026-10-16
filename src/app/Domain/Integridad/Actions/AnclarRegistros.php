<?php

namespace App\Domain\Integridad\Actions;

use App\Domain\Integridad\Anclaje\AnchorDriver;
use App\Domain\Integridad\Merkle;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Anclaje diario (sync-e-integridad §6.2). `anclar()`: registros sin inclusión en un anclaje vivo (no fallido), por `id`, hasta
 * 2^20 → Merkle RFC 6962 sobre los eslabones → anclaje + una inclusión (con su prueba) por registro, en una transacción; después
 * se sella. `actualizar()`: reintenta sellos pendientes (5 intentos → fallido; esos registros se re-anclan en el próximo lote) y
 * consulta los enviados; al confirmar, los registros pasan a «Integridad registrada» y se avisa por sync (`cambios: anclaje`).
 * Los recibos se guardan como objetos nuevos (`anclajes/{id}.ots`, `.v2.ots`…): nada se pisa.
 */
final class AnclarRegistros
{
    public const MAX_HOJAS = 1048576;

    public const MAX_INTENTOS = 5;

    public function __construct(private readonly AnchorDriver $driver, private readonly RegistrarCambio $registrarCambio) {}

    public function anclar(): ?string
    {
        $id = DB::transaction(function () {
            $registros = DB::table('registros_integridad as r')
                ->whereNotExists(fn ($q) => $q->from('anclaje_inclusiones as i')->join('anclajes as a', 'a.id', '=', 'i.anclaje_id')
                    ->whereColumn('i.registro_id', 'r.id')->where('a.estado', '<>', 'fallido'))
                ->orderBy('r.id')->limit(self::MAX_HOJAS)->get(['r.id', 'r.eslabon']);
            if ($registros->isEmpty()) {
                return null;
            }
            $arbol = Merkle::arbol($registros->pluck('eslabon')->all());
            $id = (string) Str::uuid7();
            DB::table('anclajes')->insert([
                'id' => $id, 'driver' => $this->driver->nombre(), 'raiz' => $arbol['raiz'], 'hojas' => $registros->count(),
                'desde_registro_id' => $registros->first()->id, 'hasta_registro_id' => $registros->last()->id, 'estado' => 'pendiente',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($registros->values()->chunk(1000) as $lote) {
                DB::table('anclaje_inclusiones')->insert($lote->map(fn ($r, $i) => [
                    'registro_id' => $r->id, 'anclaje_id' => $id, 'indice_hoja' => $i, 'prueba' => json_encode($arbol['pruebas'][$i]), 'created_at' => now(),
                ])->values()->all());
            }

            return $id;
        });
        if ($id !== null) {
            $this->sellar(DB::table('anclajes')->where('id', $id)->first());
        }

        return $id;
    }

    /** @return array{sellados: int, confirmados: int, fallidos: int} */
    public function actualizar(): array
    {
        $r = ['sellados' => 0, 'confirmados' => 0, 'fallidos' => 0];
        foreach (DB::table('anclajes')->where('estado', 'pendiente')->orderBy('created_at')->get() as $a) {
            $estado = $this->sellar($a);
            $r[$estado === 'fallido' ? 'fallidos' : 'sellados'] += $estado === 'pendiente' ? 0 : 1;
        }
        foreach (DB::table('anclajes')->where('estado', 'enviado')->orderBy('created_at')->get() as $a) {
            if ($this->consultar($a)) {
                $r['confirmados']++;
            }
        }

        return $r;
    }

    private function sellar(object $a): string
    {
        try {
            $recibo = $this->driver->sellar($a->raiz);
            $clave = "anclajes/{$a->id}.ots";
            Storage::put($clave, $recibo->bytes);
            DB::table('anclajes')->where('id', $a->id)->update(['estado' => 'enviado', 'red' => $recibo->red, 'enviado_en' => now(), 'recibo_clave' => $clave,
                'referencia' => json_encode($recibo->referencia), 'intentos' => $a->intentos + 1, 'error' => null, 'updated_at' => now()]);
        } catch (Throwable $e) {
            $intentos = $a->intentos + 1;
            $estado = $intentos >= self::MAX_INTENTOS ? 'fallido' : 'pendiente';
            DB::table('anclajes')->where('id', $a->id)->update(['intentos' => $intentos, 'estado' => $estado, 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            Log::log($estado === 'fallido' ? 'critical' : 'warning', 'anclaje: no se pudo sellar', ['anclaje' => $a->id, 'intentos' => $intentos, 'error' => $e->getMessage()]);

            return $estado;
        }
        $this->consultar(DB::table('anclajes')->where('id', $a->id)->first());

        return 'enviado';
    }

    private function consultar(object $a): bool
    {
        try {
            $e = $this->driver->actualizar($a->raiz, (string) Storage::get($a->recibo_clave));
        } catch (Throwable $ex) {
            Log::warning('anclaje: no se pudo actualizar', ['anclaje' => $a->id, 'error' => $ex->getMessage()]);

            return false;
        }
        $clave = $a->recibo_clave;
        if ($e->reciboNuevo !== null) {
            $n = 2;
            while (Storage::exists("anclajes/{$a->id}.v{$n}.ots")) {
                $n++;
            }
            $clave = "anclajes/{$a->id}.v{$n}.ots";
            Storage::put($clave, $e->reciboNuevo);
        }
        if ($e->estado !== 'confirmado') {
            DB::table('anclajes')->where('id', $a->id)->update(['recibo_clave' => $clave, 'updated_at' => now()]);

            return false;
        }
        DB::transaction(function () use ($a, $e, $clave) {
            DB::table('anclajes')->where('id', $a->id)->update(['estado' => 'confirmado', 'confirmado_en' => now(), 'recibo_clave' => $clave,
                'referencia' => json_encode([...(json_decode((string) $a->referencia, true) ?: []), ...($e->referencia ?? [])]), 'updated_at' => now()]);
            // La app actualiza «Anclado» por establecimiento afectado.
            $ests = DB::table('anclaje_inclusiones as i')->join('registros_integridad as r', 'r.id', '=', 'i.registro_id')
                ->join('cadenas as c', 'c.id', '=', 'r.cadena_id')->where('i.anclaje_id', $a->id)->whereNotNull('c.establecimiento_id')
                ->distinct()->pluck('c.establecimiento_id');
            foreach ($ests as $est) {
                $this->registrarCambio->handle($est, 'anclaje', $a->id, cadena: Cadena::bloquearDeEstablecimiento($est));
            }
        });

        return true;
    }
}
