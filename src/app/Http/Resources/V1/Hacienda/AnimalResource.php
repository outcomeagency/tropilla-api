<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Animal` (ficha, TPA-17): AnimalResumen + datos de la ficha, identificaciones (vigentes y
 * retiradas), madre/padre, clase de faena derivada y resumen de confianza.
 */
class AnimalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $animal = DB::table('animales')->where('id', $a->animal_id)->first();
        $idents = DB::table('animal_identificaciones')->where('animal_id', $a->animal_id)->orderByDesc('vigente')->orderBy('id')->get();
        $pariente = fn (?string $id) => $id === null ? null : [
            'id' => $id, 'identificacion_principal' => DB::table('animales_estado')->where('animal_id', $id)->value('identificacion_principal'),
        ];
        $registros = DB::table('evento_animales as ea')->join('eventos_vigentes as v', 'v.id', '=', 'ea.evento_id')
            ->where('ea.animal_id', $a->animal_id)->where('v.accion', '<>', 'anulacion')->pluck('v.registro_integridad_id');

        return [
            ...AnimalResumenResource::campos($a),
            'evento_origen_id' => $animal->evento_origen_raiz_id,
            'origen' => $a->origen,
            'fecha_nacimiento' => $a->fecha_nacimiento,
            'nacimiento_estimado' => (bool) $a->nacimiento_estimado,
            'cruza' => $a->cruza ? json_decode($a->cruza, true) : [],
            'castrado' => $a->castrado === null ? null : (bool) $a->castrado,
            'denticion' => $a->denticion,
            'madre' => $pariente($a->madre_id),
            'padre' => $pariente($a->padre_id),
            'madre_externa' => $a->madre_externa,
            'padre_externo' => $a->padre_externo,
            'senas' => $a->senas,
            'identificaciones' => $idents->map(fn ($i) => [
                'tipo' => $i->tipo, 'valor' => $i->valor, 'entidad' => $i->entidad, 'tipo_registro' => $i->tipo_registro,
                'vigente' => (bool) $i->vigente, 'en_conflicto' => (bool) $i->en_conflicto,
                'colocada_en' => $i->colocada_en, 'retirada_en' => $i->retirada_en,
            ])->all(),
            'gdp_kg_dia' => Formato::decimal($a->gdp_kg_dia),
            'condicion_corporal' => $a->cc_valor === null ? null : ['valor' => Formato::decimal($a->cc_valor), 'escala' => $a->cc_escala, 'fecha' => $a->cc_fecha],
            'partos' => (int) $a->partos,
            'ultimo_parto_fecha' => $a->ultimo_parto_fecha,
            'servicio_fecha' => $a->servicio_fecha,
            'servicio_tipo' => $a->servicio_tipo,
            'fpp_precision' => $a->fpp_precision,
            'retiro_carne_hasta' => $a->retiro_carne_hasta,
            'retiro_leche_hasta' => $a->retiro_leche_hasta,
            'clase_faena' => self::claseFaena($a),
            'baja' => $a->baja_evento_id ? ['evento_id' => $a->baja_evento_id, 'fecha' => $a->baja_en, 'tipo' => $a->baja_tipo] : null,
            'eventos_count' => (int) $a->eventos_count,
            'revisiones_abiertas' => (int) $a->revisiones_abiertas,
            'confianza_resumen' => [
                'eventos' => $registros->count(),
                'con_integridad_registrada' => $registros->filter(fn ($r) => Confianza::deRegistro((int) $r) === 'integridad_registrada')->count(),
            ],
        ];
    }

    /**
     * Clase de faena (Res. 470/2012) desde `categorias_faena`: categoría de manejo + dentición → liviano/pesado
     * por peso. Datos, no código: bovino no tiene tabla en MVP → null.
     *
     * @return array{codigo: string, abreviatura: string, clase: string}|null
     */
    public static function claseFaena(object $a): ?array
    {
        $candidatas = DB::table('categorias_faena')->where('especie_codigo', $a->especie_codigo)
            ->whereRaw('? = ANY(categorias_manejo)', [$a->categoria_codigo])->get();
        $cf = $candidatas->first(fn ($c) => $c->denticion_desde !== null && $c->denticion_desde === $a->denticion)
            ?? $candidatas->first(fn ($c) => $c->denticion_desde === null)
            ?? ($a->denticion === null ? null : $candidatas->first());
        if ($cf === null) {
            return null;
        }

        return [
            'codigo' => $cf->codigo,
            'abreviatura' => $cf->abreviatura,
            'clase' => $cf->liviano_hasta_kg !== null && $a->peso_kg !== null && (float) $a->peso_kg > (float) $cf->liviano_hasta_kg ? 'pesado' : 'liviano',
        ];
    }
}
