<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** TPA-80: reglas editables y versionadas; umbrales como datos auditables, no condicionales en el servicio. */
class ContraparteReglasSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ([
            ['bcra_situacion_roja', 'situacion_bcra', '>=', 4, 'rojo', 'bcra_central_deudores'],
            ['bcra_situacion_amarilla', 'situacion_bcra', '>=', 2, 'amarillo', 'bcra_central_deudores'],
            ['cheques_rechazados_rojo', 'cheques_rechazados', '>=', 5, 'rojo', 'bcra_central_deudores'],
            ['cheques_rechazados_amarillo', 'cheques_rechazados', '>=', 1, 'amarillo', 'bcra_central_deudores'],
            ['cheque_denunciado_rojo', 'cheque_denunciado', '==', 1, 'rojo', 'bcra_cheques_denunciados'],
            ['razon_social_inconsistente_amarillo', 'razon_social_inconsistente', '==', 1, 'amarillo', 'bcra_central_deudores'],
            ['operaciones_previas_informativa', 'operaciones_registradas', '>=', 1, 'informativa', 'tropilla'],
            ['compra_desproporcionada_amarillo', 'compra_vs_mediana', '>=', 3, 'amarillo', 'tropilla'],
        ] as [$codigo, $senal, $op, $umbral, $severidad, $fuente]) {
            DB::table('contraparte_reglas')->insertOrIgnore(['codigo' => $codigo, 'version' => 1, 'senal' => $senal, 'operador' => $op, 'umbral' => $umbral,
                'severidad' => $severidad, 'activa' => true, 'fuente' => $fuente, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
