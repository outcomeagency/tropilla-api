<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos por especie (modelo-datos.md §3.3, functional/03). **Reglas como datos**: toda diferencia
 * BOV/BUB vive acá, nunca en un `if especie`. Valores bovinos = referencia AR a validar con veterinario
 * (`validado_por_vet = false`); bubalinos de research/produccion-bubalina.md (FCV-UNNE).
 * Idempotente (upsert por código). Devuelve un hash del contenido para versionar catálogos.
 */
class EspeciesSeeder extends Seeder
{
    private const VIGENCIA = '2000-01-01';

    public static function nombres(string $es, ?string $en = null, ?string $pt = null): string
    {
        return json_encode(array_filter(['es' => $es, 'en' => $en ?? $es, 'pt' => $pt ?? $es]), JSON_UNESCAPED_UNICODE);
    }

    public function run(): void
    {
        $this->especies();
        $this->razas();
        $this->categorias();
        $this->categoriasFaena();
        $this->reglasCategoria();
        $this->parametros();
    }

    private function especies(): void
    {
        foreach ([
            ['BOV', 'Bovino', 'Cattle', 'Bovino', 'Bos taurus / Bos indicus', 1],
            ['BUB', 'Bubalino', 'Water buffalo', 'Bubalino', 'Bubalus bubalis', 2],
        ] as [$codigo, $es, $en, $pt, $cientifico, $orden]) {
            DB::table('especies')->updateOrInsert(['codigo' => $codigo], [
                'nombres' => self::nombres($es, $en, $pt), 'nombre_cientifico' => $cientifico, 'activa' => true, 'orden' => $orden,
            ]);
        }
    }

    private function razas(): void
    {
        $razas = [
            ['BOV_BRAFORD', 'BOV', 'Braford', null], ['BOV_BRANGUS', 'BOV', 'Brangus', null],
            ['BOV_HEREFORD', 'BOV', 'Hereford', null], ['BOV_ANGUS', 'BOV', 'Aberdeen Angus', null],
            ['BOV_BRAHMAN', 'BOV', 'Brahman', null], ['BOV_NELORE', 'BOV', 'Nelore', null],
            ['BOV_CRIOLLO', 'BOV', 'Criollo', null], ['BOV_CRUZA_CEBUINA', 'BOV', 'Cruza cebuina', null],
            ['BOV_CRUZA_BRITANICA', 'BOV', 'Cruza británica', null], ['BOV_OTRA', 'BOV', 'Otra', null],
            ['BUB_MEDITERRANEA', 'BUB', 'Mediterránea', 'E'], ['BUB_MURRAH', 'BUB', 'Murrah', 'U'],
            ['BUB_JAFARABADI', 'BUB', 'Jafarabadi', 'A'], ['BUB_CARABAO', 'BUB', 'Carabao (búfalo de pantano)', null],
            ['BUB_CRUZA', 'BUB', 'Cruza', null],
        ];
        foreach ($razas as $i => [$codigo, $especie, $nombre, $prefijo]) {
            DB::table('razas')->updateOrInsert(['establecimiento_id' => null, 'codigo' => $codigo], [
                'especie_codigo' => $especie, 'nombres' => self::nombres($nombre), 'prefijo_tatuaje' => $prefijo,
                'activa' => true, 'orden' => $i + 1,
            ]);
        }
    }

    private function categorias(): void
    {
        // [BOV, nombre BOV, en, BUB, nombre BUB, en, sexo, castrado, cria, vientre, reproductor]
        $filas = [
            ['BOV_TERNERO', 'Ternero', 'Calf (male)', 'BUB_BUCERRO', 'Bucerro', 'Buffalo calf (male)', 'M', null, true, false, false],
            ['BOV_TERNERA', 'Ternera', 'Calf (female)', 'BUB_BUCERRA', 'Bucerra', 'Buffalo calf (female)', 'H', null, true, false, false],
            ['BOV_NOVILLITO', 'Novillito', 'Young steer', 'BUB_BUBILLITO', 'Bubillito', 'Young buffalo steer', 'M', true, false, false, false],
            ['BOV_NOVILLO', 'Novillo', 'Steer', 'BUB_BUBILLO', 'Bubillo', 'Buffalo steer', 'M', true, false, false, false],
            ['BOV_VAQUILLONA', 'Vaquillona', 'Heifer', 'BUB_BUBILLA', 'Bubilla', 'Buffalo heifer', 'H', null, false, true, false],
            ['BOV_VACA', 'Vaca', 'Cow', 'BUB_BUFALA', 'Búfala', 'Buffalo cow', 'H', null, false, true, false],
            ['BOV_TORITO', 'Torito / MEJ', 'Young bull', 'BUB_BUTORITO', 'Butorito / búfalo entero joven', 'Young buffalo bull', 'M', false, false, false, true],
            ['BOV_TORO', 'Toro', 'Bull', 'BUB_BUFALO_TORO', 'Búfalo (toro)', 'Buffalo bull', 'M', false, false, false, true],
            ['BOV_BUEY', 'Buey', 'Ox', 'BUB_BUEY', 'Buey búfalo', 'Buffalo ox', 'M', true, false, false, false],
        ];
        foreach ($filas as $i => [$cb, $nb, $eb, $cu, $nu, $eu, $sexo, $castrado, $cria, $vientre, $repro]) {
            foreach ([[$cb, 'BOV', $nb, $eb], [$cu, 'BUB', $nu, $eu]] as [$codigo, $especie, $nombre, $en]) {
                DB::table('categorias')->updateOrInsert(['codigo' => $codigo], [
                    'especie_codigo' => $especie, 'nombres' => self::nombres($nombre, $en), 'sexo' => $sexo,
                    'castrado' => $castrado, 'es_cria' => $cria, 'es_vientre' => $vientre, 'es_reproductor' => $repro,
                    'orden' => $i + 1, 'activa' => true,
                ]);
            }
        }
    }

    private function categoriasFaena(): void
    {
        $filas = [
            ['BUB_F_BCA', 'BCA', 'Bucerra', 'H', ['BUB_BUCERRA'], null, 320],
            ['BUB_F_BCO', 'BCO', 'Bucerro', 'M', ['BUB_BUCERRO'], null, 320],
            ['BUB_F_BBA', 'BBA', 'Bubilla', 'H', ['BUB_BUBILLA'], null, 420],
            ['BUB_F_BTO', 'BTO', 'Bubillito', 'M', ['BUB_BUBILLITO'], null, 420],
            ['BUB_F_BEJ', 'BEJ', 'Búfalo entero joven', 'M', ['BUB_BUTORITO'], null, 420],
            ['BUB_F_BFA_J', 'BFA', 'Búfala joven (4 dientes)', 'H', ['BUB_BUFALA'], '4', 450],
            ['BUB_F_BBO_J', 'BBO', 'Bubillo joven (4 dientes)', 'M', ['BUB_BUBILLO'], '4', 450],
            ['BUB_F_BFA_A', 'BFA', 'Búfala adulta (6+ dientes)', 'H', ['BUB_BUFALA'], '6_mas', 530],
            ['BUB_F_BBO_A', 'BBO', 'Bubillo adulto (6+ dientes)', 'M', ['BUB_BUBILLO'], '6_mas', 530],
            ['BUB_F_BFO', 'BFO', 'Toro búfalo', 'M', ['BUB_BUFALO_TORO'], null, null],
        ];
        foreach ($filas as [$codigo, $abrev, $nombre, $sexo, $manejo, $denticion, $liviano]) {
            DB::table('categorias_faena')->updateOrInsert(['codigo' => $codigo], [
                'especie_codigo' => 'BUB', 'abreviatura' => $abrev, 'nombres' => self::nombres($nombre), 'sexo' => $sexo,
                'denticion_desde' => $denticion, 'denticion_hasta' => $denticion,
                'categorias_manejo' => '{'.implode(',', $manejo).'}', 'liviano_hasta_kg' => $liviano, 'norma' => 'Res. 470/2012',
            ]);
        }
    }

    /** §3.3: mismas reglas para ambas especies; cambia el prefijo de categoría y los umbrales (parámetros). */
    private function reglasCategoria(): void
    {
        $h = fn ($v) => ['campo' => 'sexo', 'op' => 'eq', 'valor' => $v];
        $reglas = [
            [100, 'VACA', 'BUFALA', ['todas' => [$h('H'), ['campo' => 'partos', 'op' => 'gte', 'valor' => 1]]], 'parto'],
            [95, 'VACA', 'BUFALA', ['todas' => [$h('H'), ['campo' => 'prenada', 'op' => 'eq', 'valor' => true], ['param' => 'vaca_por_prenez', 'op' => 'eq', 'valor' => true]]], 'servicio'],
            [90, 'TERNERO', 'BUCERRO', ['todas' => [$h('M'), ['campo' => 'destetado', 'op' => 'ne', 'valor' => true]]], 'edad'],
            [90, 'TERNERA', 'BUCERRA', ['todas' => [$h('H'), ['campo' => 'destetado', 'op' => 'ne', 'valor' => true]]], 'edad'],
            [80, 'VAQUILLONA', 'BUBILLA', ['todas' => [$h('H'), ['campo' => 'destetado', 'op' => 'eq', 'valor' => true], ['campo' => 'partos', 'op' => 'eq', 'valor' => 0]]], 'destete'],
            [70, 'NOVILLO', 'BUBILLO', ['todas' => [$h('M'), ['campo' => 'castrado', 'op' => 'eq', 'valor' => true], ['alguna' => [
                ['campo' => 'edad_meses', 'op' => 'gte', 'valor' => ['param' => 'edad_cambio_dientes_meses']],
                ['campo' => 'denticion', 'op' => 'in', 'valor' => ['2', '4', '6_mas']],
            ]]]], 'denticion'],
            [60, 'NOVILLITO', 'BUBILLITO', ['todas' => [$h('M'), ['campo' => 'castrado', 'op' => 'eq', 'valor' => true], ['campo' => 'destetado', 'op' => 'eq', 'valor' => true]]], 'castracion'],
            [50, 'TORO', 'BUFALO_TORO', ['todas' => [$h('M'), ['campo' => 'castrado', 'op' => 'ne', 'valor' => true], ['campo' => 'edad_meses', 'op' => 'gte', 'valor' => ['param' => 'edad_adulto_macho_meses']]]], 'edad'],
            [40, 'TORITO', 'BUTORITO', ['todas' => [$h('M'), ['campo' => 'castrado', 'op' => 'ne', 'valor' => true], ['campo' => 'destetado', 'op' => 'eq', 'valor' => true]]], 'destete'],
        ];
        // La regla 95 usa una hoja sobre un parámetro (`{param, op, valor}`): criterio del productor.
        foreach ($reglas as [$prioridad, $bov, $bub, $condiciones, $motivo]) {
            foreach (['BOV' => 'BOV_'.$bov, 'BUB' => 'BUB_'.$bub] as $especie => $categoria) {
                DB::table('reglas_categoria')->updateOrInsert(
                    ['especie_codigo' => $especie, 'establecimiento_id' => null, 'categoria_codigo' => $categoria, 'prioridad' => $prioridad],
                    ['condiciones' => json_encode($condiciones), 'motivo' => $motivo, 'activa' => true, 'version' => 1],
                );
            }
        }
    }

    private function parametros(): void
    {
        $bov = 'referencia AR (validar con vet)';
        $bub = 'FCV-UNNE / research produccion-bubalina';
        // [clave, BOV, BUB, unidad, fuente BUB]
        $porEspecie = [
            ['gestacion_media_dias', 283, 310, 'dias'],
            ['gestacion_min_dias', 270, 295, 'dias'],
            ['gestacion_max_dias', 295, 335, 'dias'],
            ['ciclo_estral_dias', 21, 21, 'dias'],
            ['servicio_posparto_min_dias', 45, 45, 'dias'],
            ['intervalo_partos_objetivo_dias', 365, 400, 'dias'],
            ['peso_min_primer_servicio_kg', null, 350, 'kg'],
            ['temporada_servicio', null, ['desde_mes' => 3, 'hasta_mes' => 7], null],
            ['relacion_toro_hembras', ['min' => 25, 'max' => 40], ['min' => 35, 'max' => 50], null],
            ['condicion_corporal_escala', '1-5', '1-9', null],
            ['rendimiento_gancho_ref_pct', '55', '53', 'pct'],
            ['desbaste_max_pct', '8', '8', 'pct'],
            ['edad_cambio_dientes_meses', 23, 16, 'meses'],
            ['edad_adulto_macho_meses', 24, 30, 'meses'],
            // Edad a la que una cría sin evento de destete se considera destetada para las reglas de categoría (bugfix TPA-25:
            // altas de crías grandes, p. ej. por push desde la app). Mismo valor que la semilla de la app.
            ['edad_destete_meses', 7, 7, 'meses'],
            ['vaca_por_prenez', false, false, 'bool'],
            ['parto_vencido_tolerancia_dias', 15, 15, 'dias'],
            ['parto_proximo_aviso_dias', 15, 15, 'dias'],
            ['pesaje_atipico_pct', 30, 30, 'pct'],
            ['pesaje_atipico_dias', 30, 30, 'dias'],
            ['pesaje_antiguo_dias', 60, 60, 'dias'],
            // Tuberculina (functional/03 §5): diferencia 72 h − 0 h en mm. Iguales hoy en ambas especies (duda 16: el vet los ajusta
            // por establecimiento con PUT …/parametros/{clave}); el cálculo lee siempre el parámetro, nunca la especie.
            ['tuberculina_anocaudal_negativo_lt_mm', '3', '3', 'mm'],
            ['tuberculina_anocaudal_positivo_gte_mm', '5', '5', 'mm'],
            ['tuberculina_cervical_simple_negativo_lt_mm', '3', '3', 'mm'],
            ['tuberculina_cervical_simple_positivo_gte_mm', '3', '3', 'mm'],
        ];
        foreach ($porEspecie as [$clave, $vBov, $vBub, $unidad]) {
            $this->parametro($clave, 'BOV', null, $vBov, $unidad, $bov);
            $this->parametro($clave, 'BUB', null, $vBub, $unidad, $bub);
        }
        // Gestación por raza bubalina (§3.3).
        foreach (['BUB_MURRAH' => 303, 'BUB_MEDITERRANEA' => 313, 'BUB_JAFARABADI' => 330] as $raza => $dias) {
            $this->parametro('gestacion_media_dias', 'BUB', $raza, $dias, 'dias', $bub);
        }
        // Brief de forraje y carga (TPA-71): equivalencias EV por categoría y supuestos del escenario. Orientativos, sin validar
        // por un profesional; el productor/asesor los ajusta por establecimiento (PUT …/parametros/{clave}) o en cada brief.
        $ev = 'Orientativo: tabla de equivalencias EV de uso habitual en AR (vaca de cría ≈ 1 EV); validar con asesor';
        $this->parametro('forraje_ev_categoria', 'BOV', null, [
            'BOV_TERNERO' => 0.4, 'BOV_TERNERA' => 0.4, 'BOV_NOVILLITO' => 0.65, 'BOV_NOVILLO' => 0.8, 'BOV_VAQUILLONA' => 0.7,
            'BOV_VACA' => 1.0, 'BOV_TORITO' => 0.9, 'BOV_TORO' => 1.3, 'BOV_BUEY' => 1.0,
        ], 'ev', $ev);
        $this->parametro('forraje_ev_categoria', 'BUB', null, [
            'BUB_BUCERRO' => 0.45, 'BUB_BUCERRA' => 0.45, 'BUB_BUBILLITO' => 0.75, 'BUB_BUBILLO' => 0.95, 'BUB_BUBILLA' => 0.8,
            'BUB_BUFALA' => 1.15, 'BUB_BUTORITO' => 1.05, 'BUB_BUFALO_TORO' => 1.5, 'BUB_BUEY' => 1.2,
        ], 'ev', 'Orientativo: equivalencia bovina ajustada por mayor peso adulto del búfalo; sin validar, ajustar con asesor');
        foreach ([
            ['forraje_consumo_ms_kg_ev_dia', '10', 'kg', 'Orientativo: ≈ 2,5 % del peso vivo de una vaca de 400 kg'],
            ['forraje_aprovechamiento_reservas_pct', '80', 'pct', 'Orientativo: pérdidas de almacenamiento y suministro'],
            ['forraje_incertidumbre_pct', '20', 'pct', 'Margen de TROPILLA para mostrar rangos, no un intervalo estadístico'],
            ['forraje_reduccion_oferta_pct', ['moderado' => 25, 'severo' => 50], 'pct', 'Escenarios ilustrativos de menor oferta de pasto, no un pronóstico'],
            ['forraje_rollo_kg', '450', 'kg', 'Orientativo: rollo de 1,5 m tal cual'],
            ['forraje_ms_pct', ['rollos' => 85, 'silo' => 35, 'suplemento' => 88], 'pct', 'Orientativo: materia seca típica por tipo de reserva'],
        ] as [$clave, $valor, $unidad, $fuente]) {
            $this->parametro($clave, null, null, $valor, $unidad, $fuente);
        }
        // Globales.
        foreach ([
            ['token_kg_default', 100, 'kg'], ['toma_vencimiento_horas', 48, 'horas'], ['entrega_tolerancia_pct', '5', 'pct'],
            ['iva_hacienda_pct', '10.5', 'pct'], ['snapshot_offline_dias', 400, 'dias'], ['plan_horizonte_dias', 90, 'dias'],
            // TPA-66: validez del resultado seronegativo de brucelosis para movimiento (operatoria vigente, Res. 67/2019) y
            // anticipación con la que se avisa un vencimiento sanitario. Editables por establecimiento.
            ['brucelosis_seronegatividad_validez_dias', 60, 'dias'], ['sanidad_oficial_aviso_dias', 30, 'dias'],
        ] as [$clave, $valor, $unidad]) {
            $this->parametro($clave, null, null, $valor, $unidad, 'TROPILLA');
        }
    }

    private function parametro(string $clave, ?string $especie, ?string $raza, mixed $valor, ?string $unidad, string $fuente): void
    {
        DB::table('parametros')->updateOrInsert(
            ['clave' => $clave, 'especie_codigo' => $especie, 'raza_codigo' => $raza, 'establecimiento_id' => null, 'vigente_desde' => self::VIGENCIA],
            ['valor' => json_encode($valor), 'unidad' => $unidad, 'fuente' => $fuente, 'validado_por_vet' => false],
        );
    }
}
