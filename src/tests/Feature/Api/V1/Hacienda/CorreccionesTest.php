<?php

use App\Domain\Hacienda\Models\Evento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/eventos";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true]);
    $this->postJson($this->url, altaBody([$this->a], '2026-01-01'))->assertCreated();
    $this->pesaje = $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->a['animal_id']]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '412.5', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-10-15'])->json('data');
});

it('corregir crea versión 2 encadenada con motivo, recalcula y «ver cambios» muestra ambas', function () {
    $v2 = $this->postJson("{$this->url}/{$this->pesaje['id']}/correcciones", [
        'motivo' => 'Se cargó 412,5 en vez de 421,5 kg', 'payload' => ['modo' => 'individual', 'peso_kg' => '421.5', 'metodo' => 'balanza'],
    ])->assertCreated()
        ->assertJsonPath('data.version', 2)->assertJsonPath('data.accion', 'correccion')
        ->assertJsonPath('data.corrige_evento_id', $this->pesaje['id'])->assertJsonPath('data.hash_anterior', $this->pesaje['huella'])
        ->assertJsonPath('data.evento_raiz_id', $this->pesaje['id'])->assertJsonPath('data.vigente', true)->json('data');

    expect(DB::table('animales_estado')->value('peso_kg'))->toBe('421.5')
        ->and(Evento::query()->count())->toBe(3); // nada se borra

    $this->getJson("{$this->url}/{$this->pesaje['id']}")->assertOk()
        ->assertJsonPath('data.evento.id', $v2['id'])->assertJsonCount(2, 'data.versiones')
        ->assertJsonPath('data.versiones.0.vigente', false)->assertJsonPath('data.versiones.0.payload.peso_kg', '412.5')
        ->assertJsonPath('data.integridad.huella', $v2['huella'])->assertJsonPath('data.integridad.confianza', 'declarado');
    $this->getJson("{$this->url}/{$v2['id']}/integridad")->assertOk()->assertJsonPath('data.seq', 3)->assertJsonPath('data.sujeto_tipo', 'evento');
});

it('409 al corregir una versión que ya no es la vigente', function () {
    $this->postJson("{$this->url}/{$this->pesaje['id']}/correcciones", ['motivo' => 'Primera', 'payload' => ['modo' => 'individual', 'peso_kg' => '420', 'metodo' => 'balanza']])->assertCreated();
    $this->postJson("{$this->url}/{$this->pesaje['id']}/correcciones", ['motivo' => 'Segunda', 'payload' => ['modo' => 'individual', 'peso_kg' => '430', 'metodo' => 'balanza']])
        ->assertStatus(409)->assertJsonPath('code', 'version_no_vigente');
});

it('anular un pesaje revierte el derivado; anular el alta deja el animal anulado y fuera del stock', function () {
    $this->postJson("{$this->url}/{$this->pesaje['id']}/anulaciones", ['motivo' => 'Pesaje de otro animal'])->assertCreated()
        ->assertJsonPath('data.accion', 'anulacion');
    expect(DB::table('animales_estado')->value('peso_kg'))->toBeNull();
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$this->a['animal_id']}/eventos")->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$this->a['animal_id']}/eventos?incluir_anulados=true")->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.anulado', true);

    $alta = Evento::query()->where('tipo', 'hacienda.alta')->sole();
    $this->postJson("{$this->url}/{$alta->id}/anulaciones", ['motivo' => 'Animal cargado por error'])->assertCreated();
    expect(DB::table('animales_estado')->value('estado'))->toBe('anulado')
        ->and(DB::table('existencias')->where('establecimiento_id', $this->est->id)->count())->toBe(0);
    $this->postJson("{$this->url}/{$alta->id}/correcciones", ['motivo' => 'x y z', 'payload' => ['motivo' => 'alta_rapida']])->assertStatus(409);
});

it('una corrección del alta no cambia la especie ni los animales creados', function () {
    $alta = Evento::query()->where('tipo', 'hacienda.alta')->sole();
    $otro = [...$this->a, 'especie' => 'BOV', 'categoria' => 'BOV_NOVILLO'];
    $this->postJson("{$this->url}/{$alta->id}/correcciones", ['motivo' => 'Era bovino', 'payload' => ['motivo' => 'inventario_inicial', 'animales' => [$otro]]])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $ok = [...$this->a, 'raza' => 'BUB_MURRAH'];
    $this->postJson("{$this->url}/{$alta->id}/correcciones", ['motivo' => 'Faltaba la raza', 'payload' => ['motivo' => 'inventario_inicial', 'animales' => [$ok]]])->assertCreated();
    expect(DB::table('animales_estado')->value('raza_codigo'))->toBe('BUB_MURRAH');
});

it('422 sin motivo; 403 lectura; 404 otro establecimiento', function () {
    $this->postJson("{$this->url}/{$this->pesaje['id']}/anulaciones", [])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    $this->postJson("{$this->url}/{$this->pesaje['id']}/correcciones", ['motivo' => 'xx y'])->assertStatus(422)->assertJsonValidationErrors(['payload']);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->url}/{$this->pesaje['id']}/anulaciones", ['motivo' => 'No va'])->assertForbidden();
    $this->getJson("{$this->url}/{$this->pesaje['id']}")->assertOk();

    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("/api/v1/establecimientos/{$otro->id}/eventos/{$this->pesaje['id']}")->assertNotFound();
    $this->postJson("/api/v1/establecimientos/{$otro->id}/eventos/{$this->pesaje['id']}/anulaciones", ['motivo' => 'No va'])->assertNotFound();
});

it('revisiones: listar, ver y resolver (descartar) con motivo', function () {
    $id = (string) Str::uuid7();
    DB::table('revisiones')->insert(['id' => $id, 'establecimiento_id' => $this->est->id, 'tipo' => 'evento_posterior_a_baja', 'entidad_tipo' => 'evento',
        'entidad_id' => $this->pesaje['id'], 'detalle' => '{}', 'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/revisiones")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/revisiones/{$id}/resolver", ['resolucion' => 'descartada', 'motivo' => 'Revisado en campo'])
        ->assertOk()->assertJsonPath('data.estado', 'descartada')->assertJsonPath('data.resuelta_por.nombre', fn ($n) => $n !== null);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/revisiones/{$id}/resolver", ['resolucion' => 'resuelta', 'motivo' => 'Otra vez'])->assertStatus(422);
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/revisiones")->assertJsonCount(0, 'data');
});
