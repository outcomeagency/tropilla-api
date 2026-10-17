<?php

use App\Models\User;
use Database\Seeders\DemoLaEsperanzaSeeder;
use Database\Seeders\DemoRegionalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('siembra la región en modo aditivo e idempotente sin alterar prueba@tropilla.ar', function () {
    Storage::fake();
    $protegido = User::factory()->create(['email' => 'prueba@tropilla.ar', 'nombre' => 'Cuenta protegida']);
    $antes = DB::table('usuarios')->where('id', $protegido->id)->value('password');

    $this->seed(DemoRegionalSeeder::class);
    $estId = DB::table('perfiles_publicos')->where('slug', 'la-esperanza')->value('establecimiento_id');
    $visibilidad = json_decode(DB::table('establecimientos')->where('id', $estId)->value('visibilidad_ficha'), true);
    $visibilidad['sanidad_renspa'] = true;
    DB::table('establecimientos')->where('id', $estId)->update(['visibilidad_ficha' => json_encode($visibilidad)]);
    $this->seed(DemoRegionalSeeder::class);

    expect(DB::table('perfiles_publicos')->whereIn('slug', ['la-esperanza', 'el-timbo', 'don-anselmo', 'los-caranday', 'la-paloma', 'santa-rita'])->count())->toBe(6)
        ->and(DB::table('documentos')->where('titulo', 'Constancia sanitaria de ejemplo (declarada)')->count())->toBe(6)
        ->and(DB::table('organizaciones')->where('slug', 'like', 'demo-%')->count())->toBe(3)
        ->and(DB::table('campo_avisos')->where('estado', 'publicado')->count())->toBe(6)
        ->and(DB::table('campo_consultas')->where('estado', 'respondida')->count())->toBe(1)
        ->and(DB::table('reproductores_externos')->where('rp', 'like', 'TPA76-BOV-%')->count())->toBe(62)
        ->and(DB::table('eventos')->where('tipo', 'reproduccion.inseminacion')->whereRaw("sobre->'payload'->'semen'->>'partida' = 'PAJ-NEA-2026-014'")->count())->toBe(1)
        ->and(DB::table('establecimientos')->where('nombre', 'La Esperanza')->value('ficha_ampliada->clima->fuente'))->toBe('INTA EEA Corrientes')
        ->and(DB::table('usuarios')->where('id', $protegido->id)->value('email'))->toBe('prueba@tropilla.ar')
        ->and(DB::table('usuarios')->where('id', $protegido->id)->value('nombre'))->toBe('Cuenta protegida')
        ->and(DB::table('usuarios')->where('id', $protegido->id)->value('password'))->toBe($antes);

    $publico = $this->getJson('/api/v1/public/establecimientos/la-esperanza')->assertOk();
    $publico->assertJsonPath('data.secciones.ficha_establecimiento.clima.precipitacion_media_mm', '1350')
        ->assertJsonPath('data.secciones.ficha_establecimiento.sanidad_renspa.renspa_vencimiento', '2027-06-30')
        ->assertJsonMissingPath('data.secciones.ficha_establecimiento.sanidad_renspa.renspa')
        ->assertJsonPath('data.secciones.ficha_establecimiento.personal.equipo', '7');

    $ramaA = DB::table('reproductores_externos')->where('rp', 'TPA76-BOV-G3-01')->value('madre_externa_id');
    $ramaB = DB::table('reproductores_externos')->where('rp', 'TPA76-BOV-G3-03')->value('padre_externa_id');
    expect($ramaA)->toBe($ramaB); // caso de consanguinidad intencional en la genealogía demo
    Sanctum::actingAs(User::query()->where('email', DemoLaEsperanzaSeeder::EMAIL)->sole());
    $animal = DB::table('animales_estado')->where('identificacion_principal', 'TPA76-IA-001')->firstOrFail();
    $this->getJson("/api/v1/establecimientos/{$animal->establecimiento_id}/animales/{$animal->animal_id}/genealogia")->assertOk()
        ->assertJsonPath('data.pedigri.madre.tipo', 'externo')
        ->assertJsonPath('data.pedigri.madre.madre.tipo', 'externo')
        ->assertJsonPath('data.pedigri.madre.madre.madre.madre.madre.tipo', 'externo');
});
