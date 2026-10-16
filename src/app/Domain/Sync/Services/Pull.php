<?php

namespace App\Domain\Sync\Services;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Hacienda\Models\Lote;
use App\Domain\Hacienda\Models\Potrero;
use App\Domain\Hacienda\Services\OcupacionPotreros;
use App\Domain\Sanidad\Models\ProductoSanitario;
use App\Domain\Sanidad\Models\Tarea;
use App\Exceptions\ApiException;
use App\Http\Resources\V1\Establecimientos\EstablecimientoResource;
use App\Http\Resources\V1\Establecimientos\MembresiaResource;
use App\Http\Resources\V1\Hacienda\AnimalResource;
use App\Http\Resources\V1\Hacienda\EventoResource;
use App\Http\Resources\V1\Hacienda\LoteResource;
use App\Http\Resources\V1\Hacienda\PotreroResource;
use App\Http\Resources\V1\Hacienda\RevisionResource;
use App\Http\Resources\V1\Sanidad\ProductoSanitarioResource;
use App\Http\Resources\V1\Sanidad\TareaResource;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /sync/pull (§7.6): cambios desde el cursor por establecimiento (`cambios.seq`), deduplicados por
 * (entidad, id) dentro de la página y **hidratados con el estado actual**. Filtrado por rol: `comercial` y
 * `fiscal` solo para dueño/admin. Cursor = base64url("v1:" + establecimiento + ":" + seq).
 */
final class Pull
{
    public static function cursor(string $establecimientoId, int $seq): string
    {
        return rtrim(strtr(base64_encode("v1:{$establecimientoId}:{$seq}"), '+/', '-_'), '=');
    }

    public static function leerCursor(?string $cursor, string $establecimientoId): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $txt = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($txt === false || ! preg_match('/^v1:([0-9a-f-]{36}):(\d+)$/', $txt, $m) || $m[1] !== $establecimientoId) {
            throw new ApiException(422, 'cursor_invalido', __('El cursor no es de este establecimiento.'));
        }

        return (int) $m[2];
    }

    /** @return array<string, mixed> */
    public function handle(Establecimiento $est, Rol $rol, Request $request, ?string $cursor, int $limite): array
    {
        $desde = self::leerCursor($cursor, $est->id);
        $visibles = $rol->puede('comercial.precios') ? ['general', 'comercial', 'fiscal'] : ['general'];

        $filas = DB::table('cambios')->where('establecimiento_id', $est->id)->where('seq', '>', $desde)
            ->orderBy('seq')->limit($limite + 1)->get();
        $hayMas = $filas->count() > $limite;
        $filas = $filas->take($limite);
        $ultimo = $filas->last()?->seq ?? $desde;

        $ultimos = [];
        foreach ($filas as $f) {
            if (in_array($f->visibilidad, $visibles, true)) {
                $ultimos[$f->entidad.'|'.$f->entidad_id] = $f;
            }
        }
        $cambios = [];
        foreach ($ultimos as $f) {
            $datos = $f->op === 'ocultar' ? null : $this->hidratar($est, $f->entidad, $f->entidad_id, $request);
            $cambios[] = ['seq' => (int) $f->seq, 'entidad' => $f->entidad, 'id' => $f->entidad_id,
                'op' => $datos === null ? 'ocultar' : $f->op, 'datos' => $datos];
        }
        usort($cambios, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return [
            'cambios' => $cambios,
            'cursor' => self::cursor($est->id, (int) $ultimo),
            'hay_mas' => $hayMas,
            'catalogos_version' => Catalogos::version(),
            'servidor_en' => Formato::fechaHora(now()),
        ];
    }

    /** @return array<string, mixed>|null estado actual de la entidad (null = ya no existe o no es visible) */
    public function hidratar(Establecimiento $est, string $entidad, string $id, Request $request): ?array
    {
        return match ($entidad) {
            'evento' => ($e = Evento::query()->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? EventoResource::make($e)->resolve($request) : null,
            'animal' => ($a = ConsultasAnimales::uno($est->id, $id)) ? AnimalResource::make($a)->resolve($request) : null,
            'lote' => ($l = Lote::query()->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? LoteResource::make($l)->resolve($request) : null,
            'potrero' => ($p = Potrero::query()->where('id', $id)->where('establecimiento_id', $est->id)->first())
                ? PotreroResource::make($p->setAttribute('ocupacion', OcupacionPotreros::de($est->id, [$p->id])[$p->id]))->resolve($request) : null,
            'producto_sanitario' => ($ps = ProductoSanitario::query()->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? ProductoSanitarioResource::make($ps)->resolve($request) : null,
            'tarea' => ($t = Tarea::query()->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? TareaResource::make($t->setAttribute('zona_horaria', $est->zona_horaria))->resolve($request) : null,
            'reproductor_externo' => ($r = DB::table('reproductores_externos')->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? (array) $r : null,
            'documento' => ($d = DB::table('documentos')->where('id', $id)->where('establecimiento_id', $est->id)->first())
                ? ConsultasDocumentos::documento($d, Carbon::now($est->zona_horaria)->toDateString(), true) : null,
            'anclaje' => ($a = DB::table('anclajes')->where('id', $id)->first()) ? ['id' => $a->id, 'estado' => $a->estado, 'driver' => $a->driver, 'red' => $a->red,
                'raiz' => $a->raiz, 'desde_registro_id' => (int) $a->desde_registro_id, 'hasta_registro_id' => (int) $a->hasta_registro_id,
                'confirmado_en' => $a->confirmado_en ? Formato::fechaHora(Carbon::parse($a->confirmado_en)) : null,
                // ids de los eventos de este establecimiento que pasan a «Integridad registrada»
                'evento_ids' => DB::table('anclaje_inclusiones as i')->join('registros_integridad as r', 'r.id', '=', 'i.registro_id')
                    ->join('cadenas as c', 'c.id', '=', 'r.cadena_id')->where('i.anclaje_id', $a->id)->where('c.establecimiento_id', $est->id)
                    ->where('r.sujeto_tipo', 'evento')->pluck('r.sujeto_id')->all()] : null,
            'establecimiento' => EstablecimientoResource::make($est->loadMissing('productor'))->resolve($request),
            'membresia' => ($m = Membresia::query()->with('usuario')->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? MembresiaResource::make($m)->resolve($request) : null,
            'revision' => ($r = DB::table('revisiones')->where('id', $id)->where('establecimiento_id', $est->id)->first()) ? RevisionResource::make($r)->resolve($request) : null,
            default => null,
        };
    }
}
