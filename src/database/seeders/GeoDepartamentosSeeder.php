<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Departamentos INDEC (modelo-datos.md §3.1). MVP: Corrientes completo (25 departamentos).
 * `garrapata_sugerida = erradicacion` para Mercedes, Goya, Curuzú Cuatiá, Paso de los Libres,
 * Esquina, Sauce y Monte Caseros (functional/03 §4.4); el resto sin sugerencia.
 * Centroide = cabecera departamental aproximada (solo para la «zona general» pública).
 * Otras provincias se cargan cuando se prioricen; mientras tanto el alta valida solo el formato.
 */
class GeoDepartamentosSeeder extends Seeder
{
    private const CORRIENTES = [
        // codigo, nombre, lat, lon, garrapata
        ['18007', 'Bella Vista', -28.509, -59.044, null],
        ['18014', 'Berón de Astrada', -27.551, -57.538, null],
        ['18021', 'Capital', -27.469, -58.830, null],
        ['18028', 'Concepción', -28.392, -57.887, null],
        ['18035', 'Curuzú Cuatiá', -29.792, -58.054, 'erradicacion'],
        ['18042', 'Empedrado', -27.951, -58.806, null],
        ['18049', 'Esquina', -30.014, -59.528, 'erradicacion'],
        ['18056', 'General Alvear', -29.097, -56.554, null],
        ['18063', 'General Paz', -27.751, -57.621, null],
        ['18070', 'Goya', -29.140, -59.263, 'erradicacion'],
        ['18077', 'Itatí', -27.270, -58.244, null],
        ['18084', 'Ituzaingó', -27.590, -56.688, null],
        ['18091', 'Lavalle', -28.986, -59.104, null],
        ['18098', 'Mburucuyá', -28.046, -58.228, null],
        ['18105', 'Mercedes', -29.184, -58.075, 'erradicacion'],
        ['18112', 'Monte Caseros', -30.252, -57.636, 'erradicacion'],
        ['18119', 'Paso de los Libres', -29.712, -57.088, 'erradicacion'],
        ['18126', 'Saladas', -28.254, -58.623, null],
        ['18133', 'San Cosme', -27.371, -58.512, null],
        ['18140', 'San Luis del Palmar', -27.508, -58.555, null],
        ['18147', 'San Martín', -29.174, -56.646, null],
        ['18154', 'San Miguel', -27.995, -57.590, null],
        ['18161', 'San Roque', -28.574, -58.709, null],
        ['18168', 'Santo Tomé', -28.549, -56.041, null],
        ['18175', 'Sauce', -30.084, -58.786, 'erradicacion'],
    ];

    public function run(): void
    {
        foreach (self::CORRIENTES as [$codigo, $nombre, $lat, $lon, $garrapata]) {
            DB::table('geo_departamentos')->updateOrInsert(['codigo' => $codigo], [
                'provincia_codigo' => '18',
                'provincia_nombre' => 'Corrientes',
                'nombre' => $nombre,
                'garrapata_sugerida' => $garrapata,
                'centroide' => DB::raw(sprintf("ST_GeogFromText('SRID=4326;POINT(%F %F)')", $lon, $lat)),
            ]);
        }
    }
}
