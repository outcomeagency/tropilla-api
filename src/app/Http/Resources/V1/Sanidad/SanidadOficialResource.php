<?php

namespace App\Http\Resources\V1\Sanidad;

use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** `Protocolo`, `ProtocoloResultado`, `EstatusSanitario` y `PlanSaneamiento` (TPA-66). Datos privados del establecimiento. */
final class SanidadOficialResource
{
    /** @return array<string, mixed> */
    public static function protocolo(object $p, bool $detalle = false, ?string $hoy = null, int $diasAviso = 30): array
    {
        $conteo = DB::table('protocolo_resultados')->where('protocolo_id', $p->id)->selectRaw('resultado, count(*) n, count(animal_id) asociados')->groupBy('resultado')->get();
        $porResultado = array_fill_keys(SanidadOficial::RESULTADOS, 0);
        foreach ($conteo as $c) {
            $porResultado[$c->resultado] = (int) $c->n;
        }
        $hoy ??= now()->toDateString();
        $out = [
            'id' => $p->id, 'enfermedad' => $p->enfermedad, 'prueba' => $p->prueba_codigo, 'prueba_nombre' => self::nombrePrueba($p->prueba_codigo),
            'motivo' => $p->motivo, 'modalidad' => $p->modalidad, 'stock_base' => $p->stock_base === null ? null : (int) $p->stock_base,
            'fecha_muestreo' => $p->fecha_muestreo, 'fecha_resultado' => $p->fecha_resultado, 'laboratorio' => $p->laboratorio,
            'protocolo_numero' => $p->protocolo_numero, 'veterinario' => ['nombre' => $p->veterinario_nombre, 'matricula' => $p->veterinario_matricula],
            'vence_el' => $p->vence_el, 'vigencia' => $p->estado === 'anulado' ? 'anulado' : SanidadOficial::vigencia($p->vence_el, $hoy, $diasAviso),
            'origen' => $p->origen, 'origen_leyenda' => SanidadOficial::leyendaOrigen($p->origen), 'documento_id' => $p->documento_id, 'borrador_id' => $p->borrador_id,
            'estado' => $p->estado, 'observaciones' => $p->observaciones,
            'resumen' => ['total' => array_sum($porResultado), 'asociados' => (int) $conteo->sum('asociados'), ...$porResultado],
            'evento_ids' => json_decode($p->evento_ids, true), 'creado_por' => $p->creado_por, 'created_at' => self::fh($p->created_at),
            'anulado_en' => self::fh($p->anulado_en), 'anulado_motivo' => $p->anulado_motivo,
        ];
        if ($detalle) {
            $out['resultados'] = DB::table('protocolo_resultados as r')->leftJoin('animales_estado as a', 'a.animal_id', '=', 'r.animal_id')
                ->where('r.protocolo_id', $p->id)->orderBy('r.orden')
                ->get(['r.*', 'a.identificacion_principal', 'a.categoria_codigo as categoria_actual', 'a.especie_codigo', 'a.estado as animal_estado'])
                ->map(fn ($r) => self::resultado($r))->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function resultado(object $r): array
    {
        return [
            'orden' => (int) $r->orden, 'animal_id' => $r->animal_id, 'identificacion' => $r->identificacion,
            'animal' => $r->animal_id === null ? null : ['identificacion_principal' => $r->identificacion_principal ?? null, 'especie' => $r->especie_codigo ?? null,
                'categoria' => $r->categoria_actual ?? null, 'estado' => $r->animal_estado ?? null],
            'categoria_codigo' => $r->categoria_codigo, 'muestra' => $r->muestra, 'resultado' => $r->resultado,
            'lectura_0h_mm' => Formato::decimal($r->lectura_0h_mm), 'lectura_72h_mm' => Formato::decimal($r->lectura_72h_mm),
            'observaciones' => $r->observaciones, 'evento_id' => $r->evento_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function estatus(object $e, string $hoy, int $diasAviso): array
    {
        return [
            'id' => $e->id, 'enfermedad' => $e->enfermedad, 'estatus' => $e->estatus, 'origen' => $e->origen, 'origen_leyenda' => SanidadOficial::leyendaOrigen($e->origen),
            'certificado_numero' => $e->certificado_numero, 'autoridad' => $e->autoridad, 'emitido_el' => $e->emitido_el,
            'vigente_desde' => $e->vigente_desde, 'vence_el' => $e->vence_el,
            'vigencia' => $e->estado === 'vigente' ? SanidadOficial::vigencia($e->vence_el, $hoy, $diasAviso) : $e->estado,
            'dias_para_vencer' => $e->vence_el === null ? null : (int) Carbon::parse($hoy)->diffInDays(Carbon::parse($e->vence_el), false),
            'protocolo_id' => $e->protocolo_id, 'documento_id' => $e->documento_id, 'observaciones' => $e->observaciones, 'estado' => $e->estado,
            'reemplazado_por' => $e->reemplazado_por, 'creado_por' => $e->creado_por, 'created_at' => self::fh($e->created_at),
            'anulado_en' => self::fh($e->anulado_en), 'anulado_motivo' => $e->anulado_motivo,
        ];
    }

    /** @return array<string, mixed> */
    public static function plan(object $p, string $hoy): array
    {
        $acciones = json_decode($p->acciones, true);

        return [
            'id' => $p->id, 'enfermedad' => $p->enfermedad, 'estado' => $p->estado, 'inicio' => $p->inicio, 'proximo_muestreo' => $p->proximo_muestreo,
            'veterinario' => ['nombre' => $p->veterinario_nombre, 'matricula' => $p->veterinario_matricula],
            'protocolo_id' => $p->protocolo_id, 'documento_id' => $p->documento_id, 'observaciones' => $p->observaciones,
            'acciones' => array_map(fn ($a) => [...$a, 'vencida' => $a['hecha_el'] === null && $a['fecha_objetivo'] !== null && $a['fecha_objetivo'] < $hoy], $acciones),
            'acciones_pendientes' => count(array_filter($acciones, fn ($a) => $a['hecha_el'] === null)),
            'cerrado_el' => $p->cerrado_el, 'row_version' => (int) $p->row_version, 'creado_por' => $p->creado_por,
            'created_at' => self::fh($p->created_at), 'updated_at' => self::fh($p->updated_at),
        ];
    }

    public static function nombrePrueba(string $codigo): string
    {
        static $nombres = [];
        $nombres[$codigo] ??= (json_decode((string) DB::table('tipos_prueba')->where('codigo', $codigo)->value('nombres'), true)['es'] ?? $codigo);

        return $nombres[$codigo];
    }

    private static function fh(?string $t): ?string
    {
        return $t === null ? null : Formato::fechaHora(Carbon::parse($t));
    }
}
