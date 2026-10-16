<?php

use App\Models\User;
use Database\Seeders\DemoDirectorioSeeder;
use Database\Seeders\DemoLaEsperanzaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('demo del directorio: 6 perfiles con portada, 8 ofertas con foto, 3 profesionales, fotos de animales por evento; idempotente', function () {
    Storage::fake();
    $this->seed(DemoLaEsperanzaSeeder::class);
    $this->seed(DemoDirectorioSeeder::class);

    $dir = $this->getJson('/api/v1/public/directorio?locale=en&limit=50')->assertOk()->json('data');
    expect(collect($dir)->pluck('slug')->sort()->values()->all())->toBe(['don-anselmo', 'el-timbo', 'la-esperanza', 'la-paloma', 'los-caranday', 'santa-rita'])
        ->and(collect($dir)->every(fn ($d) => $d['foto']['ancho'] === 1536 && $d['foto']['alt'] !== null && $d['foto_url'] === $d['foto']['url']))->toBeTrue();
    $portada = collect($dir)->firstWhere('slug', 'santa-rita')['foto_url'];
    $this->get(parse_url($portada, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/webp');

    // 8 ofertas publicadas, cada una con su foto congelada en la publicación vigente.
    $ofertas = DB::table('ofertas as o')->join('oferta_publicaciones as p', fn ($j) => $j->on('p.oferta_id', '=', 'o.id')->on('p.version', '=', 'o.publicacion_vigente'))
        ->where('o.estado', 'publicada')->get(['o.codigo_publico', 'p.contenido']);
    expect($ofertas)->toHaveCount(8)->and($ofertas->every(fn ($o) => count(json_decode($o->contenido, true)['fotos']) === 1))->toBeTrue();
    $bucerros = DB::table('ofertas')->whereRaw("textos->'es'->>'titulo' = '16 bucerros castrados'")->value('codigo_publico');
    $this->getJson("/api/v1/public/ofertas/{$bucerros}?locale=pt")->assertOk()->assertJsonPath('data.contenido.peso.min_kg', '230')
        ->assertJsonPath('data.contenido.peso.max_kg', '250')->assertJsonPath('data.contenido.fotos.0.alt', 'Bezerros bubalinos castrados de 230 a 250 kg');
    $this->getJson('/api/v1/public/establecimientos/la-esperanza')->assertOk()->assertJsonPath('data.secciones.ofertas.0.foto.ancho', 1536);

    $this->getJson('/api/v1/public/profesionales/carolina-aguirre')->assertOk()->assertJsonPath('data.foto.ancho', 800)
        ->assertJsonPath('data.establecimientos.0.slug', 'la-esperanza');
    expect(DB::table('profesionales')->where('publico', true)->whereNotNull('foto_sha256')->count())->toBe(3);

    // Fotos de animales: por evento hacienda.actualizacion_ficha (sobrevive a la reproyección).
    expect(DB::table('animales_estado')->whereNotNull('foto_sha256')->count())->toBe(4)
        ->and(DB::table('eventos')->where('tipo', 'hacienda.actualizacion_ficha')->count())->toBe(4);
    $ramon = User::query()->where('email', 'demo@tropilla.ar')->sole();
    expect($ramon->foto_sha256)->not->toBeNull();
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
    $this->artisan('proyecciones:reconstruir')->assertSuccessful();
    expect(DB::table('animales_estado')->whereNotNull('foto_sha256')->count())->toBe(4);

    Sanctum::actingAs(User::query()->where('email', 'santarita@tropilla.test')->sole());
    $estId = DB::table('perfiles_publicos')->where('slug', 'santa-rita')->value('establecimiento_id');
    $this->getJson("/api/v1/establecimientos/{$estId}/animales?q=J-001")->assertOk()->assertJsonPath('data.0.foto_url', fn ($u) => str_contains($u, 'signature='));

    $eventos = DB::table('eventos')->count();
    $archivos = DB::table('archivos')->count();
    $this->seed(DemoDirectorioSeeder::class);
    expect(DB::table('eventos')->count())->toBe($eventos)->and(DB::table('archivos')->count())->toBe($archivos)->and(DB::table('archivos')->where('mime', 'image/webp')->count())->toBe(22);
});
