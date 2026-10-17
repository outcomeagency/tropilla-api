<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tipos de evento (+ JSON Schema v1 de `database/data/eventos-payload-schemas.json`, copia del brain),
 * tipos de documento, tipos de prueba, listas chicas (`catalogo_items`) y plantillas sanitarias
 * (modelo-datos.md §3.3). Idempotente.
 */
class TiposYListasSeeder extends Seeder
{
    public const AVISO_VET = 'Validá este plan con tu veterinario. Los productos, dosis y fechas definitivos los decide un profesional.';

    /** Hembra/macho requeridos por tipo (bloquea, p. ej., parto en macho). Dato, no código. */
    private const REQUIERE_SEXO = [
        'reproduccion.parto' => 'H', 'reproduccion.aborto' => 'H', 'reproduccion.tacto' => 'H',
        'reproduccion.inseminacion' => 'H', 'reproduccion.celo' => 'H', 'reproduccion.control_lechero' => 'H',
        'reproduccion.secado' => 'H', 'reproduccion.evaluacion_toro' => 'M', 'hacienda.castracion' => 'M',
    ];

    private const NOMBRES_EN = [
        'hacienda.alta' => 'Stock entry', 'hacienda.nacimiento' => 'Birth', 'hacienda.ingreso' => 'Purchase / arrival',
        'hacienda.egreso' => 'Sale / departure', 'hacienda.traslado' => 'Paddock move', 'hacienda.cambio_lote' => 'Group change',
        'hacienda.identificacion' => 'Identification', 'hacienda.pesaje' => 'Weighing', 'hacienda.condicion_corporal' => 'Body condition',
        'hacienda.recategorizacion' => 'Recategorization', 'hacienda.castracion' => 'Castration', 'hacienda.muerte' => 'Death',
        'hacienda.perdida' => 'Loss / theft', 'hacienda.faena' => 'Slaughter', 'hacienda.denticion' => 'Dentition',
        'hacienda.actualizacion_ficha' => 'Record update', 'hacienda.individualizacion' => 'Individualization',
        'reproduccion.servicio_natural' => 'Natural service', 'reproduccion.inseminacion' => 'Insemination',
        'reproduccion.tacto' => 'Pregnancy check', 'reproduccion.parto' => 'Calving', 'reproduccion.aborto' => 'Abortion',
        'reproduccion.destete' => 'Weaning', 'sanidad.vacunacion' => 'Vaccination', 'sanidad.desparasitacion' => 'Deworming',
        'sanidad.tratamiento' => 'Treatment', 'sanidad.prueba_diagnostica' => 'Diagnostic test',
        'sanidad.analisis_laboratorio' => 'Lab analysis', 'sanidad.observacion_clinica' => 'Clinical observation',
        'establecimiento.interdiccion' => 'Sanitary interdiction', 'establecimiento.levantamiento_interdiccion' => 'Interdiction lifted',
        'establecimiento.recorrida' => 'Field round',
    ];

    public function run(): void
    {
        $this->tiposEvento();
        $this->tiposDocumento();
        $this->tiposPrueba();
        $this->listas();
        $this->plantillas();
    }

    private function tiposEvento(): void
    {
        $fuente = json_decode((string) file_get_contents(database_path('data/eventos-payload-schemas.json')), true, flags: JSON_THROW_ON_ERROR);
        $orden = 0;
        foreach ($fuente['tipos'] as $codigo => $t) {
            $sujetos = [];
            foreach (explode('|', $t['sujetos']) as $s) {
                $s = trim(preg_replace('/\s*\(.*\)$/', '', trim($s)));
                $sujetos[] = $s;
            }
            DB::table('tipos_evento')->updateOrInsert(['codigo' => $codigo], [
                'modulo' => explode('.', $codigo)[0],
                'nombres' => EspeciesSeeder::nombres($t['nombre'], self::NOMBRES_EN[$codigo] ?? null),
                'fase' => $t['fase'],
                'activo' => true,
                'crea_animales' => (bool) $t['crea_animales'],
                'requiere_sexo' => self::REQUIERE_SEXO[$codigo] ?? null,
                'sujetos_admitidos' => '{'.implode(',', array_values(array_unique($sujetos))).'}',
                'orden' => ++$orden,
            ]);

            $schema = ['$schema' => $fuente['$schema'], '$id' => $fuente['$id'].'/'.$codigo, '$defs' => $fuente['$defs'], ...$t['payload']];
            DB::table('tipos_evento_versiones')->updateOrInsert(['tipo_codigo' => $codigo, 'version' => 1], [
                'json_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'vigente_desde' => '2026-01-01',
            ]);
        }
    }

    private function tiposDocumento(): void
    {
        $tipos = [
            ['certificado_sanitario', 'Certificado sanitario', 'sanitario', true], ['vacunacion_aftosa', 'Acta de vacunación aftosa', 'sanitario', true],
            ['vacunacion_rabia', 'Vacunación antirrábica', 'sanitario', true], ['informe_tuberculina', 'Informe de tuberculina', 'sanitario', true],
            ['analisis_laboratorio', 'Análisis de laboratorio', 'sanitario', true],
            ['libro_tratamientos', 'Libro de registro de tratamientos', 'sanitario', false], ['fidha', 'FIDHA (inspección de garrapata)', 'sanitario', true],
            ['constancia_renspa', 'Constancia de RENSPA', 'general', true],
            ['dte_copia', 'Copia de DT-e', 'comercial', true], ['guia_provincial', 'Guía provincial / DUT', 'comercial', false],
            ['boleto_compraventa', 'Boleto de compraventa', 'comercial', true],
            ['resultado_faena', 'Resultado de faena / romaneo', 'comercial', false], ['liquidacion_hacienda', 'Liquidación de hacienda', 'comercial', false],
            ['factura', 'Factura', 'fiscal', false], ['constancia_fiscal', 'Constancia fiscal', 'fiscal', false],
            ['impuesto_tasa', 'Impuesto o tasa', 'fiscal', false], ['certificado_bienestar_organico', 'Certificado de bienestar / orgánico', 'general', true],
            ['registro_genealogico', 'Registro genealógico', 'general', true], ['habilitacion', 'Habilitación', 'general', true],
            ['contrato', 'Contrato', 'comercial', true], ['resumen_trato', 'Resumen de trato', 'comercial', true],
            ['contrato_futuro_pdf', 'Contrato a futuro', 'comercial', true], ['foto_evidencia', 'Foto de evidencia', 'general', true],
            ['planilla_identificacion', 'Planilla de identificación (caravanas)', 'general', false],
            ['otro', 'Otro', 'general', true],
        ];
        foreach ($tipos as $i => [$codigo, $nombre, $privacidad, $publicable]) {
            DB::table('tipos_documento')->updateOrInsert(['codigo' => $codigo], [
                'nombres' => EspeciesSeeder::nombres($nombre), 'privacidad_default' => $privacidad, 'publicable' => $publicable,
                'aviso' => $codigo === 'constancia_renspa' ? 'Contiene el RENSPA del establecimiento (dato privado).' : null,
                'orden' => $i + 1,
            ]);
        }
    }

    private function tiposPrueba(): void
    {
        $tipos = [
            ['tuberculina_anocaudal', 'Tuberculina pliegue anocaudal', 'diferencia_mm', ['negativo_lt' => '3', 'positivo_gte' => '5']],
            ['tuberculina_cervical_simple', 'Tuberculina cervical simple', 'diferencia_mm', ['negativo_lt' => '3', 'positivo_gte' => '3']],
            ['tuberculina_cervical_comparada', 'Tuberculina cervical comparada', 'declarado', null],
            ['brucelosis_bpa', 'Brucelosis BPA', 'declarado', null],
            ['brucelosis_fpa', 'Brucelosis FPA', 'declarado', null],
            // TPA-66: pruebas de la red de laboratorios (Res. SENASA 67/2019): tamiz BPA, confirmatorias SAT/2-ME, FPA, ELISA y FC.
            ['brucelosis_sat', 'Brucelosis SAT (seroaglutinación lenta)', 'declarado', null],
            ['brucelosis_2me', 'Brucelosis 2-ME (2-mercaptoetanol)', 'declarado', null],
            ['brucelosis_elisa', 'Brucelosis ELISA', 'declarado', null],
            ['brucelosis_fc', 'Brucelosis fijación del complemento', 'declarado', null],
            ['otra', 'Otra', 'declarado', null],
        ];
        foreach ($tipos as [$codigo, $nombre, $metodo, $umbrales]) {
            DB::table('tipos_prueba')->updateOrInsert(['codigo' => $codigo], [
                'enfermedad' => match (true) {
                    str_starts_with($codigo, 'brucelosis_') => 'brucelosis', str_starts_with($codigo, 'tuberculina_') => 'tuberculosis', default => null,
                },
                'nombres' => EspeciesSeeder::nombres($nombre), 'especies' => '{BOV,BUB}', 'metodo_calculo' => $metodo,
                'umbrales' => $umbrales === null ? null : json_encode($umbrales), 'version' => 1, 'validado_por_vet' => false,
            ]);
        }
    }

    private function listas(): void
    {
        $listas = [
            'causas_muerte' => ['enfermedad' => 'Enfermedad', 'accidente' => 'Accidente', 'ahogamiento' => 'Ahogamiento', 'parto_distocico' => 'Parto distócico', 'predador' => 'Predador', 'rayo' => 'Rayo', 'intoxicacion' => 'Intoxicación', 'desconocida' => 'Desconocida', 'otra' => 'Otra'],
            'enfermedades' => ['aftosa' => 'Fiebre aftosa', 'rabia' => 'Rabia paresiante', 'carbunclo' => 'Carbunclo', 'clostridiales' => 'Clostridiales', 'brucelosis' => 'Brucelosis', 'reproductivas' => 'Reproductivas', 'queratoconjuntivitis' => 'Queratoconjuntivitis', 'tristeza_parasitaria' => 'Tristeza parasitaria', 'tuberculosis' => 'Tuberculosis', 'parasitos_internos' => 'Parásitos internos', 'parasitos_externos' => 'Parásitos externos', 'otra' => 'Otra'],
            'ambientes' => ['loma' => 'Loma', 'media_loma' => 'Media loma', 'bajo' => 'Bajo', 'malezal' => 'Malezal', 'banado' => 'Bañado', 'estero' => 'Estero', 'isla' => 'Isla'],
            'recursos_forrajeros' => ['campo_natural' => 'Campo natural', 'pastura_implantada' => 'Pastura implantada', 'verdeo' => 'Verdeo'],
            'actividades' => ['cria' => 'Cría', 'recria' => 'Recría', 'invernada' => 'Invernada / engorde', 'feedlot' => 'Feedlot', 'cabana' => 'Cabaña', 'tambo' => 'Tambo'],
            'instalaciones' => ['manga' => 'Manga', 'balanza' => 'Balanza', 'cepo' => 'Cepo', 'embarcadero' => 'Embarcadero', 'aguadas' => 'Aguadas', 'revolcaderos' => 'Revolcaderos'],
            'destinos' => ['faena' => 'Faena', 'invernada' => 'Invernada', 'cria' => 'Cría', 'exportacion' => 'Exportación', 'otro' => 'Otro'],
            'tipos_comprador' => ['frigorifico' => 'Frigorífico', 'exportador' => 'Exportador', 'gastronomico' => 'Gastronómico', 'institucion' => 'Institución', 'productor' => 'Productor', 'otro' => 'Otro'],
            'signos_clinicos' => ['cojera' => 'Cojera', 'diarrea' => 'Diarrea', 'tos' => 'Tos', 'fiebre' => 'Fiebre', 'decaimiento' => 'Decaimiento', 'lesion_piel' => 'Lesión de piel', 'secrecion_nasal' => 'Secreción nasal', 'aborto' => 'Aborto', 'otro' => 'Otro'],
            'principios_activos' => ['ivermectina' => 'Ivermectina', 'doramectina' => 'Doramectina', 'albendazol' => 'Albendazol', 'levamisol' => 'Levamisol', 'fipronil' => 'Fipronil', 'cipermetrina' => 'Cipermetrina', 'fluazuron' => 'Fluazurón', 'closantel' => 'Closantel', 'triclabendazol' => 'Triclabendazol', 'otro' => 'Otro'],
        ];
        foreach ($listas as $lista => $items) {
            $orden = 0;
            foreach ($items as $codigo => $nombre) {
                DB::table('catalogo_items')->updateOrInsert(['lista' => $lista, 'establecimiento_id' => null, 'codigo' => $codigo], [
                    'nombres' => EspeciesSeeder::nombres($nombre), 'especies' => null, 'orden' => ++$orden, 'activo' => true,
                ]);
            }
        }
    }

    /** Plantillas = sugerencias (functional/03 §4): sin dosis, siempre con el aviso veterinario. */
    private function plantillas(): void
    {
        $todas = ['categorias' => []];
        $plantillas = [
            ['aftosa_campana_1', null, 'Aftosa — campaña 1', 'sanidad.vacunacion', 'aftosa', $todas, ['tipo' => 'ventana', 'configurable' => true], ['cada' => 'anual'], null, null, 'SENASA (calendario de la zona)', true],
            ['aftosa_campana_2', null, 'Aftosa — campaña 2 (menores)', 'sanidad.vacunacion', 'aftosa', ['excluye_vientres' => true, 'excluye_reproductores' => true, 'excluye_bueyes' => true], ['tipo' => 'ventana', 'configurable' => true], ['cada' => 'anual'], null, null, 'SENASA (calendario de la zona)', true],
            ['rabia_paresiante', null, 'Rabia paresiante', 'sanidad.vacunacion', 'rabia', $todas, ['tipo' => 'fecha_fija', 'configurable' => true], ['refuerzo_dias' => ['min' => 20, 'max' => 60], 'luego' => 'anual'], ['alguna' => [['campo' => 'zona.rabia_endemica', 'op' => 'eq', 'valor' => true], ['campo' => 'interdiccion_vigente', 'op' => 'eq', 'valor' => true]]], null, 'functional/03 §4.1', true],
            ['tuberculinizacion', null, 'Tuberculinización', 'sanidad.prueba_diagnostica', 'tuberculosis', $todas, ['tipo' => 'manual'], ['cada' => 'anual'], null, 'Obligatoria en tambos y cabañas.', 'functional/03 §4.1', true],
            ['brucelosis', null, 'Brucelosis', 'sanidad.vacunacion', 'brucelosis', ['reposicion' => true], ['tipo' => 'manual'], null, null, 'Contenido normativo pendiente (A7).', 'functional/03 §4.1', false],
            ['bub_toxocara_neonatal', 'BUB', 'Antiparasitario neonatal (Toxocara vitulorum)', 'sanidad.desparasitacion', 'parasitos_internos', ['categorias' => ['BUB_BUCERRO', 'BUB_BUCERRA']], ['tipo' => 'relativo', 'evento' => 'nacimiento', 'dias' => 7], null, null, null, 'FCV-UNNE', true],
            ['bub_coprologico_mensual', 'BUB', 'Coprológico mensual', 'sanidad.analisis_laboratorio', 'parasitos_internos', ['categorias' => ['BUB_BUCERRO', 'BUB_BUCERRA']], ['tipo' => 'periodico', 'cada_dias' => 30], ['hasta' => 'destete'], null, null, 'FCV-UNNE', true],
            ['bub_coccidios_20d', 'BUB', 'Control de coccidios', 'sanidad.tratamiento', 'parasitos_internos', ['categorias' => ['BUB_BUCERRO', 'BUB_BUCERRA']], ['tipo' => 'periodico', 'cada_dias' => 20], ['hasta' => 'destete'], null, null, 'FCV-UNNE', true],
            ['bub_tripanosomiasis', 'BUB', 'Tripanosomiasis', 'sanidad.tratamiento', 'otra', ['categorias' => ['BUB_BUCERRO', 'BUB_BUCERRA']], ['tipo' => 'relativo', 'evento' => 'nacimiento', 'semanas' => [20, 40]], null, null, null, 'FCV-UNNE', true],
            ['bub_fasciolicida', 'BUB', 'Fasciolicida', 'sanidad.desparasitacion', 'parasitos_internos', ['categorias' => ['BUB_BUBILLITO', 'BUB_BUBILLA', 'BUB_BUTORITO']], ['tipo' => 'ventana', 'meses' => [4, 5]], null, ['campo' => 'zona.fasciola_endemica', 'op' => 'eq', 'valor' => true], 'La dosis de referencia en búfalos difiere de la bovina: solo informativo, la define el veterinario.', 'FCV-UNNE', true],
            ['bub_gastroenteritis', 'BUB', 'Gastroenteritis verminosa', 'sanidad.desparasitacion', 'parasitos_internos', ['adultos' => true], ['tipo' => 'manual', 'segun' => 'coprologico'], null, null, null, 'FCV-UNNE', true],
            ['bub_pediculosis', 'BUB', 'Pediculosis', 'sanidad.desparasitacion', 'parasitos_externos', $todas, ['tipo' => 'ventana', 'meses' => [4, 5, 6]], ['repetir_dias' => 15], null, null, 'FCV-UNNE', true],
            ['garrapata_rotacion', null, 'Garrapata (rotación de principios activos)', 'sanidad.desparasitacion', 'parasitos_externos', $todas, ['tipo' => 'manual'], null, ['campo' => 'zona.garrapata', 'op' => 'ne', 'valor' => 'libre'], 'Dosificar por el animal más pesado; rotar principios activos.', 'functional/03 §4.4', true],
            ['plantilla_libre', null, 'Plantilla libre', 'sanidad.vacunacion', null, $todas, ['tipo' => 'manual'], null, null, null, 'TROPILLA', true],
        ];
        foreach ($plantillas as [$codigo, $especie, $nombre, $tipo, $enfermedad, $alcance, $disparador, $repeticion, $condicion, $nota, $fuente, $activa]) {
            DB::table('plantillas_sanitarias')->updateOrInsert(['codigo' => $codigo], [
                'especie_codigo' => $especie, 'nombres' => EspeciesSeeder::nombres($nombre), 'tipo_evento' => $tipo,
                'enfermedad' => $enfermedad, 'alcance' => json_encode($alcance ?: new \stdClass), 'disparador' => json_encode($disparador),
                'repeticion' => $repeticion === null ? null : json_encode($repeticion), 'condicion' => $condicion === null ? null : json_encode($condicion),
                'nota' => $nota, 'aviso' => self::AVISO_VET, 'fuente' => $fuente, 'version' => 1, 'activa' => $activa,
            ]);
        }
    }
}
