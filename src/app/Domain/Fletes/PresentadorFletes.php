<?php

namespace App\Domain\Fletes;

use App\Domain\Contrapartes\Cuit;
use App\Domain\Establecimientos\Data\Provincias;
use App\Support\Formato;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Salida JSON de pedidos, propuestas y transportistas del piloto (TPA-73). La vista del productor no muestra el contacto del
 * transportista hasta que acepta su propuesta; la del concierge (staff) ve todo para poder coordinar.
 */
final class PresentadorFletes
{
    public static function basePedidos(): Builder
    {
        return DB::table('fletes_pedidos as p')->join('usuarios as u', 'u.id', '=', 'p.usuario_id')
            ->join('establecimientos as e', 'e.id', '=', 'p.establecimiento_id')
            ->select('p.*', 'u.nombre as usuario_nombre', 'u.email as usuario_email', 'e.nombre as establecimiento_nombre', 'e.localidad as establecimiento_localidad');
    }

    /** @return array<string, mixed> FletePedido */
    public static function pedido(object $p, bool $completo, bool $concierge = false): array
    {
        $propuestas = DB::table('fletes_propuestas as pr')->join('fletes_transportistas as t', 't.id', '=', 'pr.transportista_id')
            ->leftJoin('fletes_disponibilidades as d', 'd.id', '=', 'pr.disponibilidad_id')
            ->where('pr.pedido_id', $p->id)->orderBy('pr.created_at')
            ->get(['pr.*', 't.nombre as transportista_nombre', 't.contacto_nombre', 't.telefono', 't.email', 'd.jaula', 'd.capacidad_cabezas', 'd.es_retorno']);

        $salida = [
            'id' => $p->id, 'estado' => $p->estado, 'en_corredor' => (bool) $p->en_corredor,
            'motivo' => $p->motivo, 'motivo_nombre' => Fletes::MOTIVOS[$p->motivo] ?? $p->motivo,
            'origen' => ['departamento_codigo' => $p->origen_departamento_codigo, 'departamento_nombre' => Fletes::departamentoNombre($p->origen_departamento_codigo)],
            'destino' => ['provincia_codigo' => $p->destino_provincia_codigo, 'provincia_nombre' => Provincias::TODAS[$p->destino_provincia_codigo] ?? $p->destino_provincia_codigo,
                'localidad' => $p->destino_localidad],
            'fecha_desde' => $p->fecha_desde, 'fecha_hasta' => $p->fecha_hasta,
            'especie_codigo' => $p->especie_codigo, 'categoria' => $p->categoria, 'cabezas' => (int) $p->cabezas,
            'kg_promedio' => $p->kg_promedio !== null ? (int) $p->kg_promedio : null,
            'jaula_preferida' => $p->jaula_preferida, 'observaciones' => $p->observaciones, 'telefono_contacto' => $p->telefono_contacto,
            'creado_por' => $p->usuario_nombre, 'created_at' => Formato::fechaHora(Carbon::parse($p->created_at)),
            'propuestas_pendientes' => $propuestas->where('estado', 'propuesta')->count(),
            'resultado' => $p->resultado === null ? null : [
                'resultado' => $p->resultado, 'costo' => Formato::decimal($p->resultado_costo), 'km' => $p->resultado_km !== null ? (int) $p->resultado_km : null,
                'costo_por_km' => $p->resultado_costo !== null && $p->resultado_km ? Formato::decimal(round((float) $p->resultado_costo / (int) $p->resultado_km, 2)) : null,
                'nota' => $p->resultado_nota, 'registrado_en' => Formato::fechaHora(Carbon::parse($p->resultado_en)),
            ],
        ];
        if ($concierge) {
            $salida['establecimiento'] = ['id' => $p->establecimiento_id, 'nombre' => $p->establecimiento_nombre, 'localidad' => $p->establecimiento_localidad];
            $salida['contacto'] = ['nombre' => $p->usuario_nombre, 'telefono' => $p->telefono_contacto, 'email' => $p->usuario_email];
        }
        if (! $completo) {
            return $salida;
        }

        $marcas = DB::table('fletes_pedido_checklist')->where('pedido_id', $p->id)->get()->keyBy('item');
        $salida['checklist'] = collect(Fletes::CHECKLIST_PEDIDO)->map(fn ($texto, $item) => [
            'item' => $item, 'texto' => $texto, 'estado' => $marcas[$item]->estado ?? 'pendiente', 'nota' => $marcas[$item]->nota ?? null,
            'actualizado_en' => isset($marcas[$item]) ? Formato::fechaHora(Carbon::parse($marcas[$item]->updated_at)) : null,
        ])->values()->all();
        $salida['consentimiento'] = json_decode($p->consentimiento, true);
        $salida['propuestas'] = $propuestas->map(fn ($pr) => self::propuesta($pr, $concierge))->values()->all();
        $salida['historial'] = DB::table('fletes_bitacora as b')->join('usuarios as u', 'u.id', '=', 'b.usuario_id')
            ->where('b.pedido_id', $p->id)->orderBy('b.created_at')->orderBy('b.id')->get(['b.*', 'u.nombre', 'u.es_staff'])
            ->map(fn ($b) => ['accion' => $b->accion, 'detalle' => json_decode($b->detalle, true),
                'quien' => $b->es_staff && ! $concierge && $b->usuario_id !== $p->usuario_id ? 'Equipo TROPILLA' : $b->nombre, 'en' => Formato::fechaHora(Carbon::parse($b->created_at))])->all();

        return $salida;
    }

    /** @return array<string, mixed> FletePropuesta */
    private static function propuesta(object $pr, bool $concierge): array
    {
        $doc = Fletes::documentacion($pr->transportista_id);
        $verContacto = $concierge || $pr->estado === 'aceptada';

        return [
            'id' => $pr->id, 'estado' => $pr->estado, 'fecha_propuesta' => $pr->fecha_propuesta,
            'tarifa_estimada' => Formato::decimal($pr->tarifa_estimada), 'nota' => $pr->nota, 'motivo_rechazo' => $pr->motivo_rechazo,
            'jaula' => $pr->jaula, 'jaula_nombre' => $pr->jaula ? (Fletes::JAULAS[$pr->jaula] ?? $pr->jaula) : null,
            'capacidad_cabezas' => $pr->capacidad_cabezas !== null ? (int) $pr->capacidad_cabezas : null, 'es_retorno' => (bool) $pr->es_retorno,
            'compatibilidad' => json_decode($pr->compatibilidad, true),
            'transportista' => [
                'id' => $pr->transportista_id, 'nombre' => $pr->transportista_nombre,
                'documentacion_completa' => $doc['completa'],
                'documentos' => array_map(fn ($i) => array_intersect_key($i, array_flip(['item', 'texto', 'estado', 'vence_el', 'actualizado_en'])), $doc['items']),
                'contacto' => $verContacto ? ['nombre' => $pr->contacto_nombre, 'telefono' => $pr->telefono, 'email' => $pr->email] : null,
            ],
            'created_at' => Formato::fechaHora(Carbon::parse($pr->created_at)),
            'respondida_en' => $pr->respondida_en ? Formato::fechaHora(Carbon::parse($pr->respondida_en)) : null,
        ];
    }

    /** @return array<string, mixed> FleteTransportista */
    public static function transportista(object $t, bool $completo): array
    {
        $doc = Fletes::documentacion($t->id);
        $salida = [
            'id' => $t->id, 'nombre' => $t->nombre, 'cuit' => $t->cuit ? Cuit::formatear($t->cuit) : null,
            'contacto_nombre' => $t->contacto_nombre, 'telefono' => $t->telefono, 'email' => $t->email,
            'base' => ['departamento_codigo' => $t->base_departamento_codigo, 'departamento_nombre' => Fletes::departamentoNombre($t->base_departamento_codigo)],
            'activo' => (bool) $t->activo, 'notas' => $t->notas,
            'documentacion_completa' => $doc['completa'], 'documentos_faltantes' => count($doc['faltantes']),
            'disponibilidades_abiertas' => DB::table('fletes_disponibilidades')->where('transportista_id', $t->id)->where('estado', 'abierta')
                ->where('hasta', '>=', Carbon::today()->toDateString())->count(),
            'created_at' => Formato::fechaHora(Carbon::parse($t->created_at)),
        ];
        if (! $completo) {
            return $salida;
        }
        $salida['consentimiento'] = json_decode($t->consentimiento, true);
        $salida['documentos'] = $doc['items'];
        $salida['disponibilidades'] = DB::table('fletes_disponibilidades')->where('transportista_id', $t->id)->orderByDesc('desde')->limit(50)->get()
            ->map(fn ($d) => self::disponibilidad($d))->all();
        $salida['historial'] = DB::table('fletes_bitacora as b')->join('usuarios as u', 'u.id', '=', 'b.usuario_id')
            ->where('b.transportista_id', $t->id)->orderByDesc('b.created_at')->limit(50)->get(['b.*', 'u.nombre'])
            ->map(fn ($b) => ['accion' => $b->accion, 'detalle' => json_decode($b->detalle, true), 'quien' => $b->nombre, 'en' => Formato::fechaHora(Carbon::parse($b->created_at))])->all();

        return $salida;
    }

    /** @return array<string, mixed> FleteDisponibilidad */
    public static function disponibilidad(object $d): array
    {
        return [
            'id' => $d->id, 'transportista_id' => $d->transportista_id, 'transportista_nombre' => $d->transportista_nombre ?? null,
            'origen' => ['departamento_codigo' => $d->origen_departamento_codigo, 'departamento_nombre' => Fletes::departamentoNombre($d->origen_departamento_codigo)],
            'destino_provincia_codigo' => $d->destino_provincia_codigo,
            'destino_provincia_nombre' => $d->destino_provincia_codigo ? (Provincias::TODAS[$d->destino_provincia_codigo] ?? $d->destino_provincia_codigo) : null,
            'desde' => $d->desde, 'hasta' => $d->hasta, 'jaula' => $d->jaula, 'jaula_nombre' => Fletes::JAULAS[$d->jaula] ?? $d->jaula,
            'capacidad_cabezas' => (int) $d->capacidad_cabezas, 'especies' => self::pgArray($d->especies), 'es_retorno' => (bool) $d->es_retorno,
            'tarifa_km' => Formato::decimal($d->tarifa_km), 'estado' => $d->estado, 'notas' => $d->notas,
            'vigente' => $d->estado === 'abierta' && $d->hasta >= Carbon::today()->toDateString(),
        ];
    }

    /** @return list<string> */
    public static function pgArray(string|array|null $v): array
    {
        if (is_array($v)) {
            return $v;
        }

        return $v === null || $v === '{}' ? [] : explode(',', trim($v, '{}'));
    }
}
