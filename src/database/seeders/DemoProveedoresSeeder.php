<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 24 perfiles ficticios para demo del NEA. Aditivo e idempotente; no modifica usuarios reales. */
class DemoProveedoresSeeder extends Seeder
{
    /** @var list<array{string,string,string,string}> */
    private const PROVEEDORES = [
        ['Veterinaria Esteros del Iberá', 'veterinaria', '18', 'Corrientes'], ['Sanidad Rural del Taragüí', 'vacunas', '18', 'Mercedes'],
        ['Forrajes La Rinconada', 'alimento', '18', 'Goya'], ['Alambrados Mbareté', 'alambrado', '18', 'Curuzú Cuatiá'],
        ['Consignataria Paso de los Libres', 'consignataria', '18', 'Paso de los Libres'], ['Fletes Ganaderos del Paraná', 'transporte', '18', 'Bella Vista'],
        ['Agroinsumos San Roque', 'insumos', '18', 'San Roque'], ['Campos del Aguapey', 'campos', '18', 'Santo Tomé'],
        ['Veterinaria El Quebrachal', 'veterinaria', '22', 'Presidencia Roque Sáenz Peña'], ['Vacunas Norte Chaqueño', 'vacunas', '22', 'Villa Ángela'],
        ['Nutrición Ganadera La Leonesa', 'alimento', '22', 'La Leonesa'], ['Postes y Tranqueras El Impenetrable', 'alambrado', '22', 'Juan José Castelli'],
        ['Consignataria El Sudoeste', 'consignataria', '22', 'Charata'], ['Transporte Rural Bermejo', 'transporte', '22', 'General San Martín'],
        ['Insumos Agropecuarios Quitilipi', 'insumos', '22', 'Quitilipi'], ['Estancias para Pastaje Guaycolec', 'campos', '34', 'Formosa'],
        ['Veterinaria Bañado La Estrella', 'veterinaria', '34', 'Las Lomitas'], ['Biológicos del Pilcomayo', 'vacunas', '34', 'Clorinda'],
        ['Forrajes Riacho He Hé', 'alimento', '34', 'Riacho He Hé'], ['Alambrados La Fidelidad', 'alambrado', '34', 'Ingeniero Juárez'],
        ['Consignataria Pirané', 'consignataria', '34', 'Pirané'], ['Fletes Misioneros del Sur', 'transporte', '54', 'Apóstoles'],
        ['Agropecuaria Eldorado', 'insumos', '54', 'Eldorado'], ['Campos Ganaderos del Uruguay', 'campos', '54', 'San Javier'],
    ];

    private const RUBROS = [
        'veterinaria' => ['Veterinaria', 'Veterinary services', 'Serviços veterinários'], 'vacunas' => ['Vacunas y sanidad', 'Vaccines and animal health', 'Vacinas e sanidade'],
        'alimento' => ['Alimento y forraje', 'Feed and forage', 'Alimentos e forragem'], 'alambrado' => ['Alambrados', 'Fencing', 'Cercas'],
        'consignataria' => ['Consignatarias', 'Livestock auctioneers', 'Leiloeiras'], 'transporte' => ['Transporte de hacienda', 'Livestock transport', 'Transporte de gado'],
        'insumos' => ['Insumos agropecuarios', 'Farm supplies', 'Insumos agropecuários'], 'campos' => ['Campos y pastaje', 'Land and grazing', 'Campos e pastagens'],
    ];

    public function run(): void
    {
        foreach (self::RUBROS as $codigo => $nombres) {
            DB::table('rubros_proveedor')->updateOrInsert(['codigo' => $codigo], ['nombres' => json_encode(['es' => $nombres[0], 'en' => $nombres[1], 'pt' => $nombres[2]]), 'activo' => true]);
        }
        foreach (self::PROVEEDORES as $i => [$nombre, $rubro, $provincia, $localidad]) {
            $email = 'demo-proveedor-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).'@tropilla.test';
            $user = User::query()->firstOrCreate(['email' => $email], ['nombre' => $nombre, 'password' => 'tropilla-demo', 'email_verificado_en' => now()]);
            $slug = Str::slug($nombre);
            $cuit = self::cuit($i + 1);
            DB::table('proveedores')->updateOrInsert(['usuario_id' => $user->id], [
                'slug' => $slug, 'nombre_publico' => $nombre.' (demo)', 'razon_social_declarada' => $nombre,
                'cuit_cifrado' => encrypt($cuit), 'cuit_hash' => hash_hmac('sha256', $cuit, (string) config('tropilla.cuit_pepper')),
                'estado_cuit' => 'pendiente', 'fuente_cuit' => null, 'validado_en' => null,
                'descripciones' => json_encode(['es' => "Servicio regional de {$rubro} con cobertura declarada en {$localidad} y zonas rurales cercanas. Perfil ficticio para demostración.",
                    'en' => "Regional {$rubro} service with declared coverage in {$localidad} and nearby rural areas. Fictional demo profile.",
                    'pt' => "Serviço regional de {$rubro} com cobertura declarada em {$localidad} e áreas rurais próximas. Perfil fictício de demonstração."]),
                'idiomas' => json_encode(['es', 'pt']), 'provincias' => json_encode([$provincia]), 'publico' => true, 'patrocinado' => false, 'es_demo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $rubroId = DB::table('rubros_proveedor')->where('codigo', $rubro)->value('id');
            DB::table('proveedor_rubros')->insertOrIgnore(['proveedor_id' => $user->id, 'rubro_id' => $rubroId]);
            // Polígono amplio de cobertura de demo (no representa el domicilio ni la ubicación de una empresa real).
            $geo = ['type' => 'MultiPolygon', 'coordinates' => [[[[-61.5, -30.8], [-55.5, -30.8], [-55.5, -24.0], [-61.5, -24.0], [-61.5, -30.8]]]]];
            DB::statement('UPDATE proveedores SET cobertura = ST_SetSRID(ST_GeomFromGeoJSON(?),4326)::geography WHERE usuario_id = ?', [json_encode($geo), $user->id]);
        }
        $this->command?->info('24 proveedores ficticios de demo cargados (emails @tropilla.test; clave tropilla-demo).');
    }

    private static function cuit(int $n): string
    {
        $base = '30'.str_pad((string) (70000000 + $n), 8, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
            $sum += (int) $base[$i] * $weight;
        }
        $digit = 11 - ($sum % 11);
        $digit = $digit === 11 ? 0 : ($digit === 10 ? 9 : $digit);

        return $base.$digit;
    }
}
