<?php

namespace App\Domain\Sanidad;

use App\Domain\Documentos\Borradores\Familias\Fidha;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Libro de tratamientos **de TROPILLA** (TPA-65): tratamientos, desparasitaciones e inspecciones de garrapata vigentes
 * del establecimiento, buscables por texto y fecha, con los documentos fuente vinculados (foto del libro rubricado o
 * del FIDHA). Es una vista de los eventos: no reemplaza al libro foliado y rubricado (Res. 666/2011) ni al FIDHA.
 */
final class LibroTratamientos
{
    public const AVISO = 'Registro de TROPILLA armado con tus eventos. No reemplaza al Libro de Registro de Tratamientos rubricado ni al FIDHA: esos siguen siendo los documentos oficiales.';

    public const TIPOS = ['tratamiento' => 'sanidad.tratamiento', 'desparasitacion' => 'sanidad.desparasitacion', 'inspeccion' => 'sanidad.observacion_clinica'];

    /** @param array{q?: ?string, desde?: ?string, hasta?: ?string, tipo?: ?string, lote_id?: ?string} $f */
    public static function consulta(string $establecimientoId, array $f): Builder
    {
        $tipos = isset($f['tipo']) ? [self::TIPOS[$f['tipo']]] : array_values(self::TIPOS);

        return DB::table('eventos_vigentes as v')->leftJoin('lotes as l', 'l.id', '=', 'v.lote_id')
            ->where('v.establecimiento_id', $establecimientoId)->where('v.accion', '<>', 'anulacion')->whereIn('v.tipo', $tipos)
            // Observaciones clínicas: solo las inspecciones de garrapata.
            ->where(fn ($w) => $w->where('v.tipo', '<>', 'sanidad.observacion_clinica')
                ->orWhereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(v.payload->'signos', '[]'::jsonb)) s WHERE s LIKE ?)", [Fidha::SIGNO.'%']))
            ->when($f['q'] ?? null, function ($w, $q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
                $w->where(fn ($x) => $x->whereRaw('lower(v.payload::text) LIKE ?', [$like])->orWhereRaw("lower(coalesce(v.sobre->>'observaciones', '')) LIKE ?", [$like])
                    ->orWhereRaw("lower(coalesce(v.responsable, '')) LIKE ?", [$like])->orWhereRaw("lower(coalesce(l.nombre, '')) LIKE ?", [$like]));
            })
            ->when($f['desde'] ?? null, fn ($w, $d) => $w->where('v.ocurrido_en', '>=', $d))
            ->when($f['hasta'] ?? null, fn ($w, $h) => $w->where('v.ocurrido_en', '<', Carbon::parse($h)->addDay()->toDateString()))
            ->when($f['lote_id'] ?? null, fn ($w, $l) => $w->where('v.lote_id', $l))
            ->select('v.id', 'v.evento_raiz_id', 'v.tipo', 'v.ocurrido_en', 'v.payload', 'v.sujeto_tipo', 'v.lote_id', 'v.responsable', 'l.nombre as lote_nombre')
            ->selectRaw("v.sobre->>'observaciones' AS observaciones")
            ->selectRaw('(SELECT count(*) FROM evento_animales ea WHERE ea.evento_id = v.id) AS animales')
            ->selectRaw('EXISTS (SELECT 1 FROM borradores_documento b WHERE b.establecimiento_id = v.establecimiento_id AND b.evento_ids @> jsonb_build_array(v.evento_raiz_id::text)) AS transcripto')
            ->orderByDesc('v.ocurrido_en')->orderByDesc('v.id');
    }

    /**
     * @param  array<string, list<array<string, string>>>  $documentos  por evento_raiz_id
     * @return array<string, mixed>
     */
    public static function fila(object $e, array $documentos): array
    {
        $p = json_decode($e->payload, true);
        $tipo = array_search($e->tipo, self::TIPOS, true);
        $signo = collect($p['signos'] ?? [])->first(fn ($s) => str_starts_with($s, Fidha::SIGNO));

        return [
            'id' => $e->id, 'evento_raiz_id' => $e->evento_raiz_id, 'tipo' => $tipo, 'fecha' => Carbon::parse($e->ocurrido_en)->toDateString(),
            'sujeto' => ['tipo' => $e->sujeto_tipo, 'lote_id' => $e->lote_id, 'lote_nombre' => $e->lote_nombre, 'animales' => (int) $e->animales],
            'diagnostico' => match ($tipo) {
                'tratamiento' => $p['diagnostico_presuntivo'] ?? null,
                'desparasitacion' => 'Desparasitación '.($p['tipo'] ?? ''),
                default => 'Inspección de garrapata',
            },
            'producto' => $p['producto_nombre'] ?? null, 'principio_activo' => $p['principio_activo'] ?? null,
            'dosis' => $p['dosis_aplicada'] ?? null, 'duracion_dias' => $p['duracion_dias'] ?? null,
            'responsable' => $p['veterinario'] ?? $e->responsable,
            'retiro_carne_dias' => $p['retiro_carne_dias'] ?? null, 'retiro_leche_dias' => $p['retiro_leche_dias'] ?? null,
            'inspeccion' => $tipo === 'inspeccion' ? ['resultado' => $signo ? trim(substr($signo, strlen(Fidha::SIGNO) + 1)) : null, 'descripcion' => $p['descripcion'] ?? null] : null,
            'observaciones' => $e->observaciones, 'transcripto' => (bool) $e->transcripto,
            'documentos' => $documentos[$e->evento_raiz_id] ?? [],
        ];
    }

    /**
     * Documentos vinculados a cualquier versión de esos eventos (sin los fiscales si no corresponde).
     *
     * @param  list<string>  $raices
     * @return array<string, list<array<string, string>>>
     */
    public static function documentos(array $raices, bool $verFiscales): array
    {
        if ($raices === []) {
            return [];
        }

        return DB::table('documento_vinculos as dv')->join('eventos as e', 'e.id', '=', 'dv.entidad_id')->join('documentos as d', 'd.id', '=', 'dv.documento_id')
            ->where('dv.entidad_tipo', 'evento')->whereIn('e.evento_raiz_id', $raices)->where('d.estado', 'vigente')
            ->when(! $verFiscales, fn ($q) => $q->where('d.privacidad', '<>', 'fiscal'))
            ->orderBy('d.titulo')->get(['e.evento_raiz_id', 'd.id', 'd.titulo', 'd.tipo_codigo'])
            ->groupBy('evento_raiz_id')
            ->map(fn ($g) => $g->unique('id')->map(fn ($d) => ['id' => $d->id, 'titulo' => $d->titulo, 'tipo' => $d->tipo_codigo])->values()->all())->all();
    }
}
