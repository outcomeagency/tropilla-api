<?php

use Database\Seeders\DemoLaEsperanzaSeeder;
use Illuminate\Support\Facades\DB;

it('publica solo bloques opt-in y excluye RENSPA, personal, marcas y ubicación exacta', function () {
    $this->seed(DemoLaEsperanzaSeeder::class);
    $id = DB::table('establecimientos')->where('nombre', 'La Esperanza')->value('id');
    DB::table('establecimientos')->where('id', $id)->update([
        'ficha_ampliada' => json_encode([
            'sanidad_renspa' => ['renspa_vencimiento' => '2027-01-01'],
            'clima' => ['precipitacion_media_mm' => 1350, 'fuente' => 'INTA', 'fecha' => '2026-10-16', 'latitud' => '-27.1'],
            'personal' => ['encargado' => 'Nombre reservado'],
        ]),
        'visibilidad_ficha' => json_encode(['sanidad_renspa' => true, 'clima' => true, 'personal' => true]),
    ]);

    $this->getJson('/api/v1/public/establecimientos/la-esperanza')->assertOk()
        ->assertJsonPath('data.secciones.ficha_establecimiento.clima.precipitacion_media_mm', 1350)
        ->assertJsonMissingPath('data.secciones.ficha_establecimiento.sanidad_renspa')
        ->assertJsonMissingPath('data.secciones.ficha_establecimiento.personal')
        ->assertJsonMissingPath('data.secciones.ficha_establecimiento.clima.latitud');
});
