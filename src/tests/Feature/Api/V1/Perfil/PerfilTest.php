<?php

use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento(extra: ['renspa' => '18.105.0.00999/00', 'ubicacion' => ['lat' => '-29.18', 'lon' => '-58.07'], 'superficie_ha' => '2400']);
    DB::table('productores')->update(['asociaciones' => json_encode([['entidad' => 'AACB', 'numero_socio' => '0421']]), 'contacto' => json_encode(['whatsapp' => '+5493794000142', 'email' => 'x@y.com'])]);
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => crearLote($this->est), 'conteo' => ['BUB_BUFALA' => 190, 'BUB_BUBILLA' => 60]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-15'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'establecimiento'],
        'payload' => ['producto_nombre' => 'Antiaftosa', 'enfermedad' => 'aftosa'], 'ocurrido_en' => now()->subMonth()->toDateString()])->assertStatus(422); // sujeto no admitido
});

it('configurar → vista previa obligatoria → publicar (snapshot hasheado) → perfil público sin datos sensibles', function () {
    $p = $this->getJson("{$this->base}/perfil")->assertOk()->assertJsonPath('data.publicado', false)->json('data');
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'slug' => 'la-esperanza', 'en_directorio' => true,
        'textos' => ['es' => ['titulo' => 'La Esperanza', 'descripcion' => 'Búfalos y bovinos en humedales del NEA.'], 'en' => ['descripcion' => 'Buffalo and cattle in NEA wetlands.']],
        'contacto_visible' => ['whatsapp' => true]])->assertOk()->assertJsonPath('data.slug', 'la-esperanza')->assertJsonPath('data.en_directorio', true);

    $this->postJson("{$this->base}/perfil/publicar")->assertStatus(422)->assertJsonPath('code', 'preview_requerida');
    $this->getJson("{$this->base}/perfil/preview?locale=en")->assertOk()->assertJsonPath('data.secciones.rodeo.modo', 'rango')
        ->assertJsonPath('data.secciones.rodeo.por_especie.0.rango', '101–250');
    $this->postJson("{$this->base}/perfil/preview/aceptar")->assertOk();
    $this->postJson("{$this->base}/perfil/publicar")->assertOk()->assertJsonPath('data.publicado', true);

    $pub = DB::table('perfil_publicaciones')->sole();
    expect(CanonicalJson::huella(CanonicalJson::decode(DB::table('perfil_publicaciones')->selectRaw('sobre::text as s')->value('s'))))->toBe($pub->hash);

    $this->app['auth']->forgetGuards();
    $r = $this->getJson('/api/v1/public/establecimientos/la-esperanza?locale=es')->assertOk()
        ->assertJsonPath('data.nombre', 'La Esperanza')->assertJsonPath('data.secciones.portada.zona.departamento', 'Mercedes')
        ->assertJsonPath('data.secciones.portada.asociaciones', [['entidad' => 'AACB']])
        ->assertJsonPath('data.secciones.contacto', ['formulario' => true, 'email' => null, 'telefono' => null, 'whatsapp' => '+5493794000142'])
        ->assertJsonPath('data.seo.indexable', true)->assertJsonPath('data.leyenda', fn ($l) => str_contains($l, 'no certifica'));
    $json = json_encode($r->json());
    expect($json)->not->toContain('00999')->not->toContain('-29.18')->not->toContain('30712345671')->not->toContain('0421')->not->toContain('x@y.com');
});

it('rodeo exacto, congelar una sección (no se actualiza sola) y descongelar', function () {
    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'secciones' => ['rodeo' => ['modo' => 'exacto']]])->assertOk();
    $this->postJson("{$this->base}/perfil/secciones/rodeo/congelar")->assertOk();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => crearLote($this->est, 'BUB', true, 'Otro'), 'conteo' => ['BUB_BUFALA' => 10]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-02-01'])->assertCreated();
    $this->getJson("{$this->base}/perfil/preview")->assertJsonPath('data.secciones.rodeo.por_especie.0.cabezas', 250);
    $this->postJson("{$this->base}/perfil/secciones/rodeo/descongelar")->assertOk();
    $this->getJson("{$this->base}/perfil/preview")->assertJsonPath('data.secciones.rodeo.por_especie.0.cabezas', 260);
    $this->postJson("{$this->base}/perfil/secciones/inventada/congelar")->assertNotFound();
});

it('mantiene los bloques nuevos privados por defecto y publica un pedigrí externo encadenado con allowlist', function () {
    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->postJson("{$this->base}/perfil/preview/aceptar")->assertOk();
    $this->postJson("{$this->base}/perfil/publicar")->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/public/establecimientos/la-esperanza/reproductores')->assertOk()->assertJsonPath('data', []);

    $g1 = (string) Str::uuid();
    $g2 = (string) Str::uuid();
    $root = (string) Str::uuid();
    foreach ([[$g1, 'Abuelo declarado', null, null], [$g2, 'Abuela declarada', null, null], [$root, 'Toro destacado', $g1, $g2]] as [$id, $nombre, $madre, $padre]) {
        DB::table('reproductores_externos')->insert(['id' => $id, 'establecimiento_id' => $this->est->id, 'nombre' => $nombre, 'rp' => 'RP-'.$nombre,
            'registro_genealogico' => 'REG-'.$nombre, 'raza' => 'Braford', 'especie_codigo' => 'BOV', 'centro' => 'Centro declarado',
            'madre_externa_id' => $madre, 'padre_externa_id' => $padre, 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    Sanctum::actingAs(duenoDe($this->est));
    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'secciones' => [
        'reproductores' => ['activa' => true, 'ids' => [['tipo' => 'externo', 'id' => $root]]], 'composicion' => ['activa' => true], 'indicadores' => ['activa' => true],
    ]])->assertOk();
    $this->getJson("{$this->base}/perfil/preview?locale=es")->assertJsonPath('data.secciones.indicadores.antiguedad_fuente.fuente', 'Fecha de alta del establecimiento en TROPILLA')
        ->assertJsonPath('data.secciones.galeria', null);
    $this->postJson("{$this->base}/perfil/publicar")->assertOk();
    $this->app['auth']->forgetGuards();
    $r = $this->getJson('/api/v1/public/establecimientos/la-esperanza/reproductores')->assertOk()
        ->assertJsonPath('data.0.nombre', 'Toro destacado')->assertJsonPath('data.0.madre.nombre', 'Abuelo declarado')
        ->assertJsonPath('data.0.padre.nombre', 'Abuela declarada');
    $json = json_encode($r->json());
    expect($json)->toContain('Centro declarado')->toContain('REG-Toro destacado')
        ->not->toContain('establecimiento_id')->not->toContain('caravana')->not->toContain('renspa')->not->toContain('foto_sha256');
    $this->getJson('/api/v1/public/establecimientos/la-esperanza/composicion')->assertOk();
    $this->getJson('/api/v1/public/establecimientos/la-esperanza/indicadores?locale=es')->assertOk()
        ->assertJsonPath('data.antiguedad_fuente.fuente', 'Fecha de alta del establecimiento en TROPILLA');
});

it('slug: disponibilidad, cambio con redirección 301 y 404 si no está publicado', function () {
    $otro = crearEstablecimiento(extra: ['nombre' => 'Santa Inés']);
    $this->getJson("{$this->base}/perfil/slug-disponible?slug=santa-ines")->assertJsonPath('data.disponible', true);
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("/api/v1/establecimientos/{$otro->id}/perfil")->assertJsonPath('data.slug', 'santa-ines');
    Sanctum::actingAs(duenoDe($this->est));
    $this->getJson("{$this->base}/perfil/slug-disponible?slug=santa-ines")->assertJsonPath('data.disponible', false);
    $this->getJson("{$this->base}/perfil/slug-disponible?slug=X")->assertJsonPath('data.disponible', false);

    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->postJson("{$this->base}/perfil/preview/aceptar");
    $this->postJson("{$this->base}/perfil/publicar")->assertOk();
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'slug' => 'esperanza-nea'])->assertOk();
    $this->getJson("/api/v1/public/establecimientos/{$p['slug']}")->assertStatus(301)->assertHeader('Location');
    $this->getJson('/api/v1/public/establecimientos/esperanza-nea')->assertOk();
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'en_directorio' => true])->assertStatus(409);
    $this->postJson("{$this->base}/perfil/despublicar")->assertOk();
    $this->getJson('/api/v1/public/establecimientos/esperanza-nea')->assertNotFound();
});

it('permisos: asesor ve la vista previa pero no edita ni publica; encargado no ve; otro establecimiento 404', function () {
    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $this->getJson("{$this->base}/perfil/preview")->assertOk();
    $this->postJson("{$this->base}/perfil/publicar")->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/perfil")->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/perfil")->assertNotFound();
});
