<?php

use App\Domain\Catalogos\Catalogos;
use App\Domain\Catalogos\Parametros;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('GET /catalogos: paquete público con especies, categorías por especie, JSON Schemas y geo', function () {
    $res = $this->getJson('/api/v1/catalogos', ['Accept-Language' => 'es'])->assertOk()
        ->assertHeader('ETag')
        ->assertJsonPath('data.version', Catalogos::version());

    $d = $res->json('data');
    expect(collect($d['especies'])->pluck('codigo')->all())->toBe(['BOV', 'BUB'])
        ->and(collect($d['categorias'])->where('especie', 'BUB')->pluck('codigo')->all())->toContain('BUB_BUCERRA', 'BUB_BUFALA', 'BUB_BUFALO_TORO')
        ->and(collect($d['categorias'])->firstWhere('codigo', 'BUB_BUFALA')['nombre'])->toBe('Búfala')
        ->and(collect($d['razas'])->firstWhere('codigo', 'BUB_MURRAH')['prefijo_tatuaje'])->toBe('U')
        ->and($d['categorias_faena'])->toHaveCount(10)
        ->and(collect($d['tipos_evento'])->firstWhere('codigo', 'hacienda.pesaje')['json_schema']['required'])->toBe(['modo', 'peso_kg', 'metodo'])
        ->and(collect($d['tipos_evento'])->firstWhere('codigo', 'reproduccion.parto')['requiere_sexo'])->toBe('H')
        ->and($d['items']['causas_muerte'])->not->toBeEmpty()
        ->and(collect($d['geo']['departamentos'])->firstWhere('codigo', '18105')['garrapata_sugerida'])->toBe('erradicacion')
        ->and(collect($d['plantillas_sanitarias'])->every(fn ($p) => str_contains($p['aviso'], 'veterinario')))->toBeTrue();
});

it('traduce por Accept-Language y responde 304 si el cliente está al día', function () {
    $en = $this->getJson('/api/v1/catalogos', ['Accept-Language' => 'en'])->assertOk();
    expect(collect($en->json('data.categorias'))->firstWhere('codigo', 'BOV_VACA')['nombre'])->toBe('Cow');

    $v = Catalogos::version();
    $this->get("/api/v1/catalogos?version={$v}")->assertStatus(304);
    $this->get('/api/v1/catalogos', ['If-None-Match' => $en->headers->get('ETag'), 'Accept-Language' => 'en'])->assertStatus(304);
});

it('el seeder es idempotente: no sube la versión si el contenido no cambió', function () {
    $v = Catalogos::version();
    $this->seed(CatalogosSeeder::class);
    expect(Catalogos::version())->toBe($v)
        ->and(DB::table('categorias')->count())->toBe(18);
});

it('/sync/catalogos exige token', function () {
    $this->getJson('/api/v1/sync/catalogos')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/sync/catalogos')->assertOk()->assertJsonStructure(['data' => ['version', 'tipos_evento']]);
});

it('resuelve parámetros por especificidad: global, especie, raza y establecimiento (sin if especie)', function () {
    $est = crearEstablecimiento();

    expect(Parametros::valor('gestacion_media_dias', 'BOV'))->toBe(283)
        ->and(Parametros::valor('gestacion_media_dias', 'BUB'))->toBe(310)
        ->and(Parametros::valor('gestacion_media_dias', 'BUB', 'BUB_JAFARABADI'))->toBe(330)
        ->and(Parametros::valor('edad_cambio_dientes_meses', 'BUB'))->toBe(16)
        ->and(Parametros::valor('condicion_corporal_escala', 'BUB'))->toBe('1-9')
        ->and(Parametros::valor('token_kg_default'))->toBe(100);

    Sanctum::actingAs(duenoDe($est));
    $this->putJson("/api/v1/establecimientos/{$est->id}/parametros/gestacion_media_dias", ['valor' => 312, 'especie' => 'BUB', 'motivo' => 'Dato del vet'])
        ->assertOk()->assertJsonPath('data.valor', 312)->assertJsonPath('data.origen', 'establecimiento');

    expect(Parametros::valor('gestacion_media_dias', 'BUB', null, $est->id))->toBe(312)
        ->and(Parametros::valor('gestacion_media_dias', 'BUB', 'BUB_JAFARABADI', $est->id))->toBe(312) // establecimiento > raza
        ->and(Parametros::valor('gestacion_media_dias', 'BUB'))->toBe(310); // otros campos no cambian

    $lista = $this->getJson("/api/v1/establecimientos/{$est->id}/parametros?especie=BUB")->assertOk()->json('data');
    expect(collect($lista)->firstWhere('clave', 'gestacion_media_dias'))->toMatchArray(['valor' => 312, 'origen' => 'establecimiento', 'especie' => 'BUB'])
        ->and(collect($lista)->firstWhere('clave', 'token_kg_default')['origen'])->toBe('global');
});

it('PUT parámetro: 404 clave inexistente, 422 tipo distinto, 403 encargado, 404 otro establecimiento', function () {
    $est = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($est));
    $this->putJson("/api/v1/establecimientos/{$est->id}/parametros/no_existe", ['valor' => 1])->assertNotFound();
    $this->putJson("/api/v1/establecimientos/{$est->id}/parametros/vaca_por_prenez", ['valor' => 'si', 'especie' => 'BOV'])->assertStatus(422);
    $this->putJson("/api/v1/establecimientos/{$est->id}/parametros/vaca_por_prenez", [])->assertStatus(422)->assertJsonValidationErrors(['valor']);

    Sanctum::actingAs(miembro($est, 'encargado'));
    $this->putJson("/api/v1/establecimientos/{$est->id}/parametros/vaca_por_prenez", ['valor' => true, 'especie' => 'BOV'])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("/api/v1/establecimientos/{$est->id}/parametros")->assertNotFound();
});

it('catálogo propio: raza y causa de muerte con código estable e idempotente; 403 lectura; 422', function () {
    $est = crearEstablecimiento();
    Sanctum::actingAs(miembro($est, 'asesor'));

    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'razas', 'nombre' => 'Búfalo criollo', 'especie' => 'BUB'])
        ->assertCreated()->assertJsonPath('data.codigo', 'X_BUB_BUFALO_CRIOLLO');
    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'razas', 'nombre' => 'Búfalo criollo', 'especie' => 'BUB'])
        ->assertCreated()->assertJsonPath('data.codigo', 'X_BUB_BUFALO_CRIOLLO');
    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'causas_muerte', 'nombre' => 'Mordedura de víbora'])
        ->assertCreated()->assertJsonPath('data.codigo', 'x_mordedura_de_vibora');
    expect(DB::table('razas')->where('establecimiento_id', $est->id)->count())->toBe(1);

    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'razas', 'nombre' => 'Sin especie'])
        ->assertStatus(422)->assertJsonValidationErrors(['especie']);
    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'categorias', 'nombre' => 'X'])
        ->assertStatus(422)->assertJsonValidationErrors(['lista']);

    Sanctum::actingAs(miembro($est, 'lectura'));
    $this->postJson("/api/v1/establecimientos/{$est->id}/catalogo-propio", ['lista' => 'causas_muerte', 'nombre' => 'X y z'])->assertForbidden();
});
