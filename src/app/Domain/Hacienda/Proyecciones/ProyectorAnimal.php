<?php

namespace App\Domain\Hacienda\Proyecciones;

use App\Domain\Catalogos\Parametros;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula **desde cero** el estado de un animal re-aplicando sus eventos vigentes en orden
 * (`ocurrido_en`, `creado_en`, `id`) — modelo-datos.md §6.3/§6.5. Recalcular siempre (en vez de
 * incremental) hace que «reconstruir ≡ incremental» por construcción y que los eventos fuera de orden,
 * las correcciones y las anulaciones den el mismo resultado. Es idempotente.
 *
 * Los umbrales (gestación por especie/raza, etc.) salen de `parametros`: nada de `if especie`.
 */
final class ProyectorAnimal
{
    public const VERSION = 1;

    public function recalcular(string $animalId): void
    {
        $animal = DB::table('animales')->where('id', $animalId)->first();
        if ($animal === null) {
            return;
        }
        $tz = (string) DB::table('establecimientos')->where('id', $animal->establecimiento_id)->value('zona_horaria');

        // Evento de origen: versión vigente de la raíz y la última versión con payload.
        $origenVigente = DB::table('eventos_vigentes')->where('evento_raiz_id', $animal->evento_origen_raiz_id)->first();
        $origenDatos = DB::table('eventos')->where('evento_raiz_id', $animal->evento_origen_raiz_id)
            ->where('en_revision', false)->where('accion', '<>', 'anulacion')->orderByDesc('version')->first();
        if ($origenDatos === null) {
            return;
        }

        $s = $this->estadoInicial($animal, $origenDatos, $tz);
        $idents = [];
        $this->identificacionesDeAlta($idents, $animal->id, $origenDatos, $tz);

        if ($origenVigente->accion === 'anulacion') {
            $s['estado'] = 'anulado';
        }

        $eventos = DB::select(
            'SELECT v.* FROM eventos_vigentes v
             WHERE v.evento_raiz_id IN (SELECT e.evento_raiz_id FROM evento_animales ea JOIN eventos e ON e.id = ea.evento_id WHERE ea.animal_id = ?)
               AND v.evento_raiz_id <> ?
               AND v.accion <> \'anulacion\'
               AND EXISTS (SELECT 1 FROM evento_animales ea2 WHERE ea2.evento_id = v.id AND ea2.animal_id = ?)
             ORDER BY v.ocurrido_en, v.creado_en, v.id',
            [$animalId, $animal->evento_origen_raiz_id, $animalId],
        );

        $count = 1;
        $ultimo = Carbon::parse($origenDatos->ocurrido_en);
        foreach ($eventos as $e) {
            if ($s['estado'] === 'anulado') {
                break;
            }
            $this->aplicar($s, $idents, $animal, $e, $tz);
            $count++;
            $ultimo = Carbon::parse($e->ocurrido_en)->max($ultimo);
        }

        $s['eventos_count'] = $count;
        $s['ultimo_evento_ocurrido_en'] = $ultimo;
        $s['identificacion_principal'] = $this->principal($idents);
        $s['revisiones_abiertas'] = DB::table('revisiones')->where('estado', 'abierta')
            ->where(fn ($q) => $q->whereIn('entidad_id', DB::table('evento_animales')->where('animal_id', $animalId)->select('evento_id'))
                ->orWhere('detalle->animal_id', $animalId))
            ->count();
        $s['proyeccion_version'] = self::VERSION;
        $s['actualizado_en'] = now();
        $s['cruza'] = $s['cruza'] === null ? null : json_encode($s['cruza']);

        // Categoría sugerida (TPA-25): reglas por especie como datos.
        [$sugerida, $reglaId] = app(SugeridorCategoria::class)->evaluar($s, $animal->establecimiento_id);
        $s['categoria_sugerida_codigo'] = $sugerida;
        $s['categoria_sugerida_regla_id'] = $reglaId;
        $s['categoria_sugerida_en'] = $sugerida ? now() : null;

        DB::table('animales_estado')->updateOrInsert(['animal_id' => $animalId], $s);
        $this->guardarIdentificaciones($animal, $idents);
    }

    /** @return array<string, mixed> */
    private function estadoInicial(object $animal, object $origen, string $tz): array
    {
        $p = json_decode($origen->payload, true);
        $nuevo = null;
        foreach ($p['animales'] ?? [] as $a) {
            if ($a['animal_id'] === $animal->id) {
                $nuevo = $a;
            }
        }
        foreach ($p['crias'] ?? [] as $c) {
            if (($c['animal']['animal_id'] ?? null) === $animal->id) {
                $nuevo = $c['animal'];
                $nuevo['_peso_kg'] = $c['peso_kg'] ?? null;
            }
        }
        $nuevo ??= ['sexo' => 'H', 'categoria' => ''];
        $sujetos = $origen->evento_raiz_id;
        $madre = $nuevo['madre_id'] ?? null;
        if ($origen->tipo === 'reproduccion.parto' && $madre === null) {
            $madre = DB::table('evento_animales')->where('evento_id', $origen->id)->where('animal_id', '<>', $animal->id)
                ->whereNotIn('animal_id', array_values(array_filter(array_map(fn ($c) => $c['animal']['animal_id'] ?? null, $p['crias'] ?? []))))->value('animal_id');
        }
        $fecha = $this->fecha($origen->ocurrido_en, $tz);

        $s = [
            'animal_id' => $animal->id,
            'establecimiento_id' => $animal->establecimiento_id,
            'especie_codigo' => $animal->especie_codigo,
            'sexo' => $nuevo['sexo'],
            'raza_codigo' => $nuevo['raza'] ?? null,
            'cruza' => $nuevo['cruza'] ?? null,
            'fecha_nacimiento' => $nuevo['fecha_nacimiento'] ?? (in_array($origen->tipo, ['reproduccion.parto', 'hacienda.nacimiento'], true) ? $fecha : null),
            'nacimiento_estimado' => (bool) ($nuevo['nacimiento_estimado'] ?? false),
            'castrado' => $nuevo['castrado'] ?? null,
            'denticion' => $nuevo['denticion'] ?? null,
            'madre_id' => $madre,
            'padre_id' => $nuevo['padre_id'] ?? null,
            'madre_externa' => $nuevo['madre_externa'] ?? null,
            'padre_externo' => $nuevo['padre_externo'] ?? null,
            'senas' => $nuevo['senas'] ?? null,
            'foto_sha256' => $nuevo['foto'] ?? null,
            'origen' => match ($origen->tipo) {
                'hacienda.ingreso' => 'comprado',
                'hacienda.nacimiento', 'reproduccion.parto' => 'nacido',
                default => 'inventario',
            },
            'categoria_codigo' => $nuevo['categoria'],
            'estado' => 'activo',
            'baja_evento_id' => null, 'baja_tipo' => null, 'baja_en' => null,
            'lote_id' => $nuevo['lote_id'] ?? ($origen->lote_id ?? null),
            'potrero_id' => $nuevo['potrero_id'] ?? null,
            'peso_kg' => null, 'peso_fecha' => null, 'peso_metodo' => null, 'peso_anterior_kg' => null, 'peso_anterior_fecha' => null, 'gdp_kg_dia' => null,
            'cc_valor' => null, 'cc_escala' => null, 'cc_fecha' => null,
            // Un animal que entra con una categoría que no es de cría ya está destetado (dato de `categorias.es_cria`).
            'destetado' => ! (bool) DB::table('categorias')->where('codigo', $nuevo['categoria'])->value('es_cria'),
            'destete_fecha' => null, 'destete_peso_kg' => null,
            'partos' => 0, 'ultimo_parto_fecha' => null, 'estado_reproductivo' => null,
            'servicio_fecha' => null, 'servicio_evento_id' => null, 'servicio_tipo' => null,
            'tacto_fecha' => null, 'dias_gestacion_al_tacto' => null, 'fecha_probable_parto' => null, 'fpp_precision' => null,
            'reactor' => false, 'reactor_desde' => null, 'reactor_prueba' => null,
            'retiro_carne_hasta' => null, 'retiro_leche_hasta' => null,
        ];
        if (isset($nuevo['_peso_kg'])) {
            $s['peso_kg'] = $nuevo['_peso_kg'];
            $s['peso_fecha'] = $fecha;
            $s['peso_metodo'] = 'estimado';
        }
        unset($sujetos);

        return $s;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<int, array<string, mixed>>  $idents
     */
    private function aplicar(array &$s, array &$idents, object $animal, object $e, string $tz): void
    {
        $p = json_decode($e->payload, true);
        $fecha = $this->fecha($e->ocurrido_en, $tz);
        $especie = $animal->especie_codigo;

        switch ($e->tipo) {
            case 'hacienda.traslado':
                $s['potrero_id'] = $p['potrero_destino_id'];
                break;
            case 'hacienda.cambio_lote':
                $s['lote_id'] = $p['lote_destino_id'];
                break;
            case 'hacienda.identificacion':
                $this->identificacion($idents, $animal->id, $e, $p, $fecha);
                break;
            case 'hacienda.pesaje':
                if ($p['modo'] === 'individual') {
                    if ($s['peso_kg'] !== null && $s['peso_fecha'] !== null && $s['peso_fecha'] < $fecha) {
                        $s['peso_anterior_kg'] = $s['peso_kg'];
                        $s['peso_anterior_fecha'] = $s['peso_fecha'];
                    }
                    $s['peso_kg'] = $p['peso_kg'];
                    $s['peso_fecha'] = $fecha;
                    $s['peso_metodo'] = $p['metodo'];
                    $s['gdp_kg_dia'] = null;
                    if ($s['peso_anterior_kg'] !== null) {
                        $dias = Carbon::parse($s['peso_anterior_fecha'])->diffInDays(Carbon::parse($fecha), true);
                        $s['gdp_kg_dia'] = $dias > 0 ? round(((float) $s['peso_kg'] - (float) $s['peso_anterior_kg']) / $dias, 3) : null;
                    }
                }
                break;
            case 'hacienda.condicion_corporal':
                $s['cc_valor'] = $p['valor'];
                $s['cc_escala'] = $p['escala'];
                $s['cc_fecha'] = $fecha;
                break;
            case 'hacienda.recategorizacion':
                if (isset($p['categoria_nueva'])) {
                    $s['categoria_codigo'] = $p['categoria_nueva'];
                }
                break;
            case 'hacienda.castracion':
                $s['castrado'] = true;
                if (isset($p['categoria_nueva'])) {
                    $s['categoria_codigo'] = $p['categoria_nueva'];
                }
                break;
            case 'hacienda.denticion':
                $s['denticion'] = $p['denticion'];
                break;
            case 'hacienda.actualizacion_ficha':
                $mapa = ['raza' => 'raza_codigo', 'cruza' => 'cruza', 'fecha_nacimiento' => 'fecha_nacimiento', 'nacimiento_estimado' => 'nacimiento_estimado',
                    'madre_id' => 'madre_id', 'padre_id' => 'padre_id', 'madre_externa' => 'madre_externa', 'padre_externo' => 'padre_externo',
                    'senas' => 'senas', 'foto' => 'foto_sha256'];
                foreach ($p['cambios'] as $k => $v) {
                    if (isset($mapa[$k])) {
                        $s[$mapa[$k]] = $v;
                    }
                }
                break;
            case 'hacienda.egreso':
            case 'hacienda.muerte':
            case 'hacienda.perdida':
            case 'hacienda.faena':
                if ($s['estado'] === 'activo') {
                    $s['estado'] = match ($e->tipo) {
                        'hacienda.muerte' => 'muerto',
                        'hacienda.perdida' => 'perdido',
                        'hacienda.faena' => 'faenado',
                        default => in_array($p['motivo'], ['venta', 'entrega_contrato'], true) ? 'vendido' : 'transferido',
                    };
                    $s['baja_evento_id'] = $e->evento_raiz_id;
                    $s['baja_tipo'] = $e->tipo;
                    $s['baja_en'] = $fecha;
                }
                break;

                // Reproducción (TPA-27..29): estado reproductivo y fecha probable de parto con parámetros por especie/raza.
            case 'reproduccion.servicio_natural':
                // Un lote de servicio puede expandirse con los toros adentro (M6): solo cambia el estado de las hembras.
                if ($s['sexo'] !== 'H') {
                    break;
                }
                $s['estado_reproductivo'] = 'servida';
                $s['servicio_fecha'] = $p['inicio'];
                $s['servicio_evento_id'] = $e->evento_raiz_id;
                $s['servicio_tipo'] = 'natural';
                $s['fecha_probable_parto'] = $this->sumarDias($p['inicio'], $this->gestacion($s, $animal, $fecha));
                $s['fpp_precision'] = 'servicio_rango';
                break;
            case 'reproduccion.inseminacion':
                $s['estado_reproductivo'] = 'servida';
                $s['servicio_fecha'] = $fecha;
                $s['servicio_evento_id'] = $e->evento_raiz_id;
                $s['servicio_tipo'] = $p['tipo'];
                $s['fecha_probable_parto'] = $this->sumarDias($fecha, $this->gestacion($s, $animal, $fecha));
                $s['fpp_precision'] = 'ia';
                break;
            case 'reproduccion.tacto':
                if ($p['resultado'] === 'prenada') {
                    // Un tacto preñada solo por tamaño confirma la preñez pero no pisa la referencia (tacto con días o servicio) anterior.
                    if (isset($p['dias_gestacion']) || $s['estado_reproductivo'] !== 'prenada') {
                        $s['tacto_fecha'] = $fecha;
                        $s['dias_gestacion_al_tacto'] = $p['dias_gestacion'] ?? null;
                    }
                    $s['estado_reproductivo'] = 'prenada';
                    if (isset($p['dias_gestacion'])) {
                        $s['fecha_probable_parto'] = $this->sumarDias($fecha, $this->gestacion($s, $animal, $fecha) - (int) $p['dias_gestacion']);
                        $s['fpp_precision'] = 'tacto';
                    }
                } else {
                    $s['tacto_fecha'] = $fecha;
                    $s['estado_reproductivo'] = 'vacia';
                    $s['dias_gestacion_al_tacto'] = null;
                    $s['fecha_probable_parto'] = null;
                    $s['fpp_precision'] = null;
                }
                break;
            case 'reproduccion.parto':
                $s['partos']++;
                $s['ultimo_parto_fecha'] = $fecha;
                $s['estado_reproductivo'] = 'parida';
                $s['fecha_probable_parto'] = null;
                $s['fpp_precision'] = null;
                break;
            case 'reproduccion.aborto':
                $s['estado_reproductivo'] = 'vacia';
                $s['fecha_probable_parto'] = null;
                $s['fpp_precision'] = null;
                break;
            case 'reproduccion.destete':
                $s['destetado'] = true;
                $s['destete_fecha'] = $fecha;
                $s['destete_peso_kg'] = $p['peso_kg'] ?? null;
                if (isset($p['categoria_nueva'])) {
                    $s['categoria_codigo'] = $p['categoria_nueva'];
                }
                break;

                // Sanidad (TPA-32..35): retiro con el snapshot de días del payload; reactor si prueba positiva.
            case 'sanidad.vacunacion':
            case 'sanidad.desparasitacion':
            case 'sanidad.tratamiento':
                $desde = $e->tipo === 'sanidad.tratamiento' && isset($p['duracion_dias']) ? $this->sumarDias($fecha, (int) $p['duracion_dias'] - 1) : $fecha;
                foreach (['carne', 'leche'] as $t) {
                    if (isset($p["retiro_{$t}_dias"])) {
                        $hasta = $this->sumarDias($desde, (int) $p["retiro_{$t}_dias"]);
                        $s["retiro_{$t}_hasta"] = max($s["retiro_{$t}_hasta"] ?? '0000-00-00', $hasta);
                    }
                }
                break;
            case 'sanidad.prueba_diagnostica':
                if ($p['resultado'] === 'positivo' && ! $s['reactor']) {
                    $s['reactor'] = true;
                    $s['reactor_desde'] = $fecha;
                    $s['reactor_prueba'] = $p['prueba'];
                }
                break;
        }
    }

    /** @param array<int, array<string, mixed>> $idents */
    private function identificacionesDeAlta(array &$idents, string $animalId, object $origen, string $tz): void
    {
        $p = json_decode($origen->payload, true);
        $lista = [];
        foreach ($p['animales'] ?? [] as $a) {
            if ($a['animal_id'] === $animalId) {
                $lista = $a['identificaciones'] ?? [];
            }
        }
        foreach ($p['crias'] ?? [] as $c) {
            if (($c['animal']['animal_id'] ?? null) === $animalId) {
                $lista = $c['animal']['identificaciones'] ?? [];
            }
        }
        foreach ($lista as $i) {
            $idents[] = $this->ident($i, $origen->id, $i['colocada_en'] ?? $this->fecha($origen->ocurrido_en, $tz));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $idents
     * @param  array<string, mixed>  $p
     */
    private function identificacion(array &$idents, string $animalId, object $e, array $p, string $fecha): void
    {
        $retirar = $p['accion'] === 'colocacion' ? null : ($p['reemplaza'] ?? $p['identificacion']);
        if ($retirar !== null) {
            foreach ($idents as &$i) {
                if ($i['vigente'] && $i['tipo'] === $retirar['tipo'] && $i['valor_normalizado'] === Identificaciones::normalizar($retirar['valor'])) {
                    $i['vigente'] = false;
                    $i['hasta_evento_id'] = $e->id;
                    $i['retirada_en'] = $fecha;
                }
            }
            unset($i);
        }
        if ($p['accion'] !== 'retiro') {
            // Una sola vigente por tipo (salvo marca/registro): la colocación de otra del mismo tipo reemplaza la anterior.
            if (! in_array($p['identificacion']['tipo'], ['marca_senal', 'registro_genealogico'], true)) {
                foreach ($idents as &$i) {
                    if ($i['vigente'] && $i['tipo'] === $p['identificacion']['tipo']) {
                        $i['vigente'] = false;
                        $i['hasta_evento_id'] = $e->id;
                        $i['retirada_en'] = $fecha;
                    }
                }
                unset($i);
            }
            $idents[] = $this->ident($p['identificacion'], $e->id, $p['identificacion']['colocada_en'] ?? $fecha);
        }
    }

    /**
     * @param  array<string, mixed>  $i
     * @return array<string, mixed>
     */
    private function ident(array $i, string $eventoId, string $fecha): array
    {
        return [
            'tipo' => $i['tipo'], 'valor' => $i['valor'], 'valor_normalizado' => Identificaciones::normalizar($i['valor']),
            'entidad' => $i['entidad'] ?? null, 'tipo_registro' => $i['tipo_registro'] ?? null, 'vigente' => true,
            'desde_evento_id' => $eventoId, 'hasta_evento_id' => null, 'colocada_en' => $fecha, 'retirada_en' => null,
        ];
    }

    /** @param array<int, array<string, mixed>> $idents */
    private function principal(array $idents): ?string
    {
        $vigentes = array_values(array_filter($idents, fn ($i) => $i['vigente']));
        foreach (['caravana_visual', 'caravana_oficial', 'tatuaje', 'rfid', 'marca_senal', 'registro_genealogico'] as $tipo) {
            foreach ($vigentes as $i) {
                if ($i['tipo'] === $tipo) {
                    return $i['valor'];
                }
            }
        }

        return null;
    }

    /**
     * Reemplaza la proyección de identificaciones del animal. Si una vigente ya está vigente en otro animal,
     * ambas quedan `en_conflicto` (la revisión la abrió RegistrarEvento).
     *
     * @param  array<int, array<string, mixed>>  $idents
     */
    private function guardarIdentificaciones(object $animal, array $idents): void
    {
        DB::table('animal_identificaciones')->where('animal_id', $animal->id)->delete();
        foreach ($idents as $i) {
            $conflicto = false;
            if ($i['vigente']) {
                $otros = DB::table('animal_identificaciones')->where('establecimiento_id', $animal->establecimiento_id)
                    ->where('tipo', $i['tipo'])->where('valor_normalizado', $i['valor_normalizado'])->where('vigente', true)
                    ->where('animal_id', '<>', $animal->id);
                if ($otros->exists()) {
                    $conflicto = true;
                    $otros->update(['en_conflicto' => true]);
                }
            }
            DB::table('animal_identificaciones')->insert([
                ...$i, 'establecimiento_id' => $animal->establecimiento_id, 'animal_id' => $animal->id, 'en_conflicto' => $conflicto,
            ]);
        }
    }

    /** @param array<string, mixed> $s */
    private function gestacion(array $s, object $animal, string $fecha): int
    {
        return (int) Parametros::valor('gestacion_media_dias', $animal->especie_codigo, $s['raza_codigo'], $animal->establecimiento_id, Carbon::parse($fecha));
    }

    private function fecha(string $ocurridoEn, string $tz): string
    {
        return Carbon::parse($ocurridoEn)->setTimezone($tz)->toDateString();
    }

    private function sumarDias(string $fecha, int $dias): string
    {
        return Carbon::parse($fecha)->addDays($dias)->toDateString();
    }
}
