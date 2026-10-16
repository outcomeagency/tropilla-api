<?php

use App\Domain\Integridad\CanonicalJson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->productor = $this->est->productor_id;
    $this->dueno = duenoDe($this->est);
    $this->staff = User::factory()->create(['es_staff' => true]);
    $this->adminOrg = User::factory()->create(['email' => 'secretaria@aacb.example']);
    Sanctum::actingAs($this->staff);
    $this->org = $this->postJson('/api/v1/organizaciones', ['nombre' => 'Asociación Argentina de Criadores de Búfalos', 'slug' => 'aacb', 'tipo' => 'asociacion',
        'ambito' => ['pais' => 'AR'], 'especies' => ['BUB']])->assertCreated()->json('data');
    $this->postJson("/api/v1/organizaciones/{$this->org['id']}/miembros", ['email' => 'secretaria@aacb.example', 'rol' => 'admin'])->assertNoContent();
    $this->url = "/api/v1/productores/{$this->productor}/adhesiones";
    $this->alcances = ['existencias_agregadas' => true, 'cobertura_sanitaria' => false, 'lotes_postulados' => false, 'precios_agregados' => false];
});

it('adhesión con consentimiento granular hasheado en la cadena del productor; el padrón ve solo lo consentido; modificar y revocar versionan', function () {
    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", altaBody([animalNuevo(), animalNuevo()], '2026-03-01'))->assertCreated();
    $this->getJson('/api/v1/organizaciones?q=bufalos&especie=BUB')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'aacb');

    $a = $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'numero_socio' => '0421', 'alcances' => $this->alcances, 'texto_version' => 'consentimiento-org-v1'])
        ->assertCreated()->assertJsonPath('data.estado', 'activa')->assertJsonPath('data.consentimiento.version', 1)->assertJsonPath('data.consentimiento.accion', 'otorga')
        ->assertJsonPath('data.consentimiento.alcances', $this->alcances)->json('data');
    $c1 = DB::table('consentimientos')->where('adhesion_id', $a['id'])->first();
    expect(CanonicalJson::huella(CanonicalJson::decode(DB::table('consentimientos')->where('id', $c1->id)->selectRaw('sobre::text as s')->value('s'))))->toBe($c1->hash)
        ->and(DB::table('cadenas')->where('productor_id', $this->productor)->value('ultimo_seq'))->toBe(1);
    $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'alcances' => $this->alcances, 'texto_version' => 'consentimiento-org-v1'])->assertStatus(409);

    Sanctum::actingAs($this->adminOrg);
    $ad = $this->getJson("/api/v1/organizaciones/{$this->org['id']}/adheridos")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($ad['productor']['nombre'])->toBe('Agropecuaria Don Ramón S.A.')->and($ad['numero_socio'])->toBe('0421')
        ->and($ad['datos']['existencias'][0])->toBe(['especie' => 'BUB', 'cabezas' => 2])->and($ad['datos']['cobertura_sanitaria'])->toBeNull();

    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->url}/{$a['id']}/consentimiento", ['alcances' => [...$this->alcances, 'existencias_agregadas' => false, 'cobertura_sanitaria' => true], 'texto_version' => 'consentimiento-org-v1'])
        ->assertCreated()->assertJsonPath('data.consentimiento.version', 2)->assertJsonPath('data.consentimiento.accion', 'modifica');
    expect(DB::table('consentimientos')->where('adhesion_id', $a['id'])->where('version', 2)->value('sobre'))->toContain($c1->hash);
    Sanctum::actingAs($this->adminOrg);
    $ad = $this->getJson("/api/v1/organizaciones/{$this->org['id']}/adheridos")->json('data.0');
    expect($ad['datos']['existencias'])->toBeNull()->and($ad['datos']['cobertura_sanitaria'])->toBe([]);

    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->url}/{$a['id']}/revocar", ['motivo' => 'Ya no participo del programa'])->assertCreated()->assertJsonPath('data.estado', 'revocada')
        ->assertJsonPath('data.consentimiento.accion', 'revoca')->assertJsonPath('data.consentimiento.alcances.cobertura_sanitaria', false)->assertJsonCount(3, 'data.historial');
    $this->getJson($this->url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'revocada');
    Sanctum::actingAs($this->adminOrg);
    $this->getJson("/api/v1/organizaciones/{$this->org['id']}/adheridos")->assertJsonCount(0, 'data');

    // Volver a adherirse: versión 4, otorga.
    Sanctum::actingAs($this->dueno);
    $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'alcances' => $this->alcances, 'texto_version' => 'consentimiento-org-v2'])->assertCreated()
        ->assertJsonPath('data.consentimiento.version', 4);
    expect(fn () => DB::transaction(fn () => DB::table('consentimientos')->update(['texto_version' => 'x'])))->toThrow(Exception::class);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('permisos: solo dueño/admin del productor; padrón solo para la organización; alta de organización solo staff; 422', function () {
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'alcances' => $this->alcances, 'texto_version' => 'v1x'])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson($this->url)->assertNotFound();
    $this->getJson("/api/v1/organizaciones/{$this->org['id']}/adheridos")->assertNotFound();
    $this->postJson('/api/v1/organizaciones', ['nombre' => 'Trucha', 'slug' => 'trucha', 'tipo' => 'asociacion'])->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'admin'));
    $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'alcances' => ['existencias_agregadas' => 'si'], 'texto_version' => '!'])->assertStatus(422)
        ->assertJsonValidationErrors(['alcances.existencias_agregadas', 'alcances.cobertura_sanitaria', 'texto_version']);
    $this->postJson($this->url, ['organizacion_id' => (string) Str::uuid7(), 'alcances' => $this->alcances, 'texto_version' => 'v1x'])->assertNotFound();
    $this->postJson($this->url, ['organizacion_id' => $this->org['id'], 'alcances' => $this->alcances, 'texto_version' => 'consentimiento-org-v1'])->assertCreated();
});

it('administra integrantes: listar, cambiar rol y dar de baja con permisos de organización', function () {
    Sanctum::actingAs($this->adminOrg);
    $id = $this->adminOrg->id;
    $this->getJson("/api/v1/organizaciones/{$this->org['id']}/miembros")
        ->assertOk()->assertJsonPath('data.0.usuario.id', $id)->assertJsonPath('data.0.rol', 'admin');

    $operador = User::factory()->create(['email' => 'operador@aacb.example']);
    $this->postJson("/api/v1/organizaciones/{$this->org['id']}/miembros", ['email' => $operador->email, 'rol' => 'operador'])->assertNoContent();
    $this->patchJson("/api/v1/organizaciones/{$this->org['id']}/miembros/{$operador->id}", ['rol' => 'admin'])->assertNoContent();
    $miembros = collect($this->getJson("/api/v1/organizaciones/{$this->org['id']}/miembros")->assertOk()->json('data'));
    expect($miembros->firstWhere('usuario.id', $operador->id)['rol'])->toBe('admin');
    $this->deleteJson("/api/v1/organizaciones/{$this->org['id']}/miembros/{$operador->id}")->assertNoContent();
    $this->getJson("/api/v1/organizaciones/{$this->org['id']}/miembros")->assertJsonCount(1, 'data');

    Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/v1/organizaciones/{$this->org['id']}/miembros", ['email' => $operador->email, 'rol' => 'admin'])->assertNotFound();
    $this->patchJson("/api/v1/organizaciones/{$this->org['id']}/miembros/{$id}", ['rol' => 'operador'])->assertNotFound();
});
