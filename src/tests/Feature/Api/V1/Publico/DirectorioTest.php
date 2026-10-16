<?php

use App\Domain\Perfil\Services\Perfiles;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function publicarPerfil(object $est, bool $directorio = true, ?string $slug = null): void
{
    Perfiles::asegurar($est);
    DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update(array_filter([
        'publicado' => true, 'en_directorio' => $directorio, 'preview_aceptada_en' => now(), 'slug' => $slug,
    ], fn ($v) => $v !== null));
}

beforeEach(function () {
    $this->a = crearEstablecimiento(extra: ['nombre' => 'La Esperanza']);
    $this->b = crearEstablecimiento(extra: ['nombre' => 'Santa Inés', 'departamento_codigo' => '18070']);
    DB::table('establecimientos')->where('id', $this->b->id)->update(['especies' => '{BOV}']);
    $this->c = crearEstablecimiento(extra: ['nombre' => 'Oculto']);
    publicarPerfil($this->a);
    publicarPerfil($this->b);
    publicarPerfil($this->c, false);
    Sanctum::actingAs(duenoDe($this->a));
    $this->postJson("/api/v1/establecimientos/{$this->a->id}/eventos", altaBody([animalNuevo(['raza' => 'BUB_MURRAH'])]))->assertCreated();
    $this->app['auth']->forgetGuards();
});

it('lista solo perfiles publicados con directorio activado, con filtros y búsqueda sin acentos', function () {
    $this->getJson('/api/v1/public/directorio')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.nombre', 'La Esperanza') // actividad más reciente primero
        ->assertJsonPath('data.0.razas', ['BUB_MURRAH'])->assertJsonPath('data.0.departamento', 'Mercedes');
    $this->getJson('/api/v1/public/directorio?especie=BUB')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/directorio?raza=BUB_MURRAH')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/directorio?categoria=BUB_BUFALA')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/directorio?departamento=18070')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Santa Inés');
    $this->getJson('/api/v1/public/directorio?q=santa ines')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/directorio?q=goya')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/directorio?con_oferta=true')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/public/directorio?q=oculto')->assertJsonCount(0, 'data');
});

it('paginado por cursor y facetas', function () {
    $p = $this->getJson('/api/v1/public/directorio?limit=1')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/public/directorio?limit=1&cursor={$p->json('meta.next_cursor')}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Santa Inés');
    $this->getJson('/api/v1/public/directorio/facetas?locale=es')->assertOk()
        ->assertJsonPath('data.provincias.0', ['codigo' => '18', 'nombre' => 'Corrientes', 'count' => 2])
        ->assertJsonPath('data.especies.0.codigo', 'BUB')->assertJsonPath('data.razas.0.nombre', 'Murrah');
});

it('sitemap: solo perfiles del directorio (y sus ofertas publicadas)', function () {
    $paths = collect($this->getJson('/api/v1/public/sitemap')->assertOk()->json('data'))->pluck('path')->all();
    expect($paths)->toHaveCount(2)->each->toStartWith('/p/');
});
