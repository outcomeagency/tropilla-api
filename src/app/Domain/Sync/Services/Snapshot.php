<?php

namespace App\Domain\Sync\Services;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Hacienda\Models\Lote;
use App\Domain\Hacienda\Models\Potrero;
use App\Domain\Hacienda\Services\OcupacionPotreros;
use App\Http\Resources\V1\Establecimientos\EstablecimientoResource;
use App\Http\Resources\V1\Establecimientos\MembresiaResource;
use App\Http\Resources\V1\Hacienda\AnimalResource;
use App\Http\Resources\V1\Hacienda\EventoResource;
use App\Http\Resources\V1\Hacienda\LoteResource;
use App\Http\Resources\V1\Hacienda\PotreroResource;
use App\Http\Resources\V1\Hacienda\RevisionResource;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /sync/snapshot (§7.7): bootstrap del establecimiento activo — estado actual completo + eventos vigentes de los
 * últimos N días (parámetro `snapshot_offline_dias`, 400) — y `cursor` = `ultimo_seq_cambios` leído en la misma
 * transacción REPEATABLE READ, para seguir con pull sin huecos.
 */
final class Snapshot
{
    /** @return array<string, mixed> */
    public function handle(Establecimiento $est, Request $request): array
    {
        return DB::transaction(function () use ($est, $request) {
            if (DB::transactionLevel() === 1) { // en tests corre dentro de la transacción de RefreshDatabase
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $seq = (int) DB::table('cadenas')->where('establecimiento_id', $est->id)->value('ultimo_seq_cambios');
            $dias = (int) (Parametros::valor('snapshot_offline_dias', null, null, $est->id) ?? 400);
            $desde = now()->subDays($dias);

            $potreros = Potrero::query()->where('establecimiento_id', $est->id)->get();
            $ocupacion = OcupacionPotreros::de($est->id, $potreros->pluck('id')->all());
            $animales = ConsultasAnimales::base($est->id)
                ->where(fn ($q) => $q->where('a.estado', 'activo')->orWhere('a.baja_en', '>=', $desde->toDateString()))->get();
            $eventos = Evento::query()->fromSub(DB::table('eventos_vigentes'), 'eventos')
                ->where('establecimiento_id', $est->id)->where('ocurrido_en', '>=', $desde)->orderBy('ocurrido_en')->get();

            return [
                'establecimiento' => EstablecimientoResource::make($est->loadMissing('productor'))->resolve($request),
                'potreros' => $potreros->map(fn ($p) => PotreroResource::make($p->setAttribute('ocupacion', $ocupacion[$p->id]))->resolve($request))->all(),
                'lotes' => Lote::query()->where('establecimiento_id', $est->id)->get()->map(fn ($l) => LoteResource::make($l)->resolve($request))->all(),
                'animales' => $animales->map(fn ($a) => AnimalResource::make($a)->resolve($request))->all(),
                'eventos' => $eventos->map(fn ($e) => EventoResource::make($e)->resolve($request))->all(),
                'documentos' => [],
                'productos_sanitarios' => [],
                'tareas' => [],
                'revisiones' => DB::table('revisiones')->where('establecimiento_id', $est->id)->where('estado', 'abierta')->get()
                    ->map(fn ($r) => RevisionResource::make($r)->resolve($request))->all(),
                'usuarios' => $est->membresias()->with('usuario')->where('estado', 'activa')->get()->map(fn ($m) => MembresiaResource::make($m)->resolve($request))->all(),
                'cursor' => Pull::cursor($est->id, $seq),
                'catalogos_version' => Catalogos::version(),
                'generado_en' => Formato::fechaHora(now()),
            ];
        });
    }
}
