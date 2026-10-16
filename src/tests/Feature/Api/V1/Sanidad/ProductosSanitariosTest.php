<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/productos-sanitarios";
});

function productoBody(array $extra = []): array
{
    return array_merge([
        'nombre_comercial' => 'Ivomec 1%', 'principio_activo' => 'ivermectina', 'tipo' => 'antiparasitario_interno', 'via' => 'subcutanea',
        'dosis_declarada' => '1 ml cada 50 kg (según vet)', 'retiro_carne_dias' => 35, 'retiro_leche_dias' => null,
        'especies' => ['BOV', 'BUB'], 'enfermedades' => ['parasitos_internos', 'parasitos_externos'],
    ], $extra);
}

it('alta, listado, edición con row_version y archivo; idempotente por id; viaja por pull y snapshot', function () {
    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $id = (string) Str::uuid7();

    $this->postJson($this->url, productoBody(['id' => $id]))->assertCreated()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.retiro_carne_dias', 35)
        ->assertJsonPath('data.dosis_declarada', '1 ml cada 50 kg (según vet)')->assertJsonPath('data.especies', ['BOV', 'BUB'])
        ->assertJsonPath('data.row_version', 1);
    $this->postJson($this->url, productoBody(['id' => $id]))->assertOk(); // reintento
    $this->postJson($this->url, productoBody(['nombre_comercial' => 'Aftosa oleosa', 'tipo' => 'vacuna', 'principio_activo' => null,
        'retiro_carne_dias' => 0, 'especies' => ['BOV'], 'enfermedades' => ['aftosa']]))->assertCreated();

    $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.nombre_comercial', 'Aftosa oleosa');
    $this->getJson("{$this->url}?tipo=vacuna")->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?especie=BUB")->assertJsonCount(1, 'data');

    $this->patchJson("{$this->url}/{$id}", ['row_version' => 1, 'retiro_carne_dias' => 42])->assertOk()
        ->assertJsonPath('data.retiro_carne_dias', 42)->assertJsonPath('data.row_version', 2);
    $this->patchJson("{$this->url}/{$id}", ['row_version' => 1, 'retiro_carne_dias' => 10])->assertStatus(409)->assertJsonPath('code', 'conflicto_row_version');

    $snap = $this->getJson("/api/v1/sync/snapshot?establecimiento_id={$this->est->id}")->assertOk();
    expect(collect($snap->json('data.productos_sanitarios'))->firstWhere('id', $id)['retiro_carne_dias'])->toBe(42);
    $cambios = collect($this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->json('data.cambios'));
    expect($cambios->firstWhere('entidad', 'producto_sanitario')['datos'])->not->toBeNull();

    $this->postJson("{$this->url}/{$id}/archivar", ['motivo' => 'Ya no se usa'])->assertOk()->assertJsonPath('data.row_version', 3)
        ->assertJson(fn ($j) => $j->whereNot('data.archivado_en', null)->etc());
    $this->getJson($this->url)->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?archivados=1")->assertJsonCount(2, 'data');
    $this->patchJson("{$this->url}/{$id}", ['row_version' => 3, 'notas' => 'x'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    expect(DB::table('auditoria')->where('entidad', 'producto_sanitario')->count())->toBeGreaterThanOrEqual(4);
});

it('base sugerida sin dosis ni retiro, filtrable por especie', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $todos = $this->getJson('/api/v1/catalogos/productos-sugeridos')->assertOk()->json('data');
    expect($todos)->not->toBeEmpty()
        ->and(collect($todos)->every(fn ($p) => $p['dosis_declarada'] === null && $p['retiro_carne_dias'] === null && $p['codigo'] !== null))->toBeTrue();
    $bub = $this->getJson('/api/v1/catalogos/productos-sugeridos?especie=BUB')->json('data');
    expect(collect($bub)->pluck('codigo'))->not->toContain('fluazuron')->and(count($bub))->toBeLessThan(count($todos));
    $this->getJson('/api/v1/catalogos/productos-sugeridos?especie=XXX')->assertStatus(422);
});

it('422 de validación, nombre repetido, 403 lectura y 404 ajeno', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->postJson($this->url, ['nombre_comercial' => 'X', 'tipo' => 'magia', 'via' => 'ocular', 'retiro_carne_dias' => -1, 'especies' => ['OVI'], 'enfermedades' => ['gripe']])
        ->assertStatus(422)->assertJsonValidationErrors(['nombre_comercial', 'tipo', 'via', 'retiro_carne_dias', 'especies.0', 'enfermedades.0']);
    $this->postJson($this->url, productoBody())->assertCreated();
    $this->postJson($this->url, productoBody(['nombre_comercial' => 'IVOMEC 1%']))->assertStatus(422)->assertJsonValidationErrors(['nombre_comercial']);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson($this->url)->assertOk();
    $this->postJson($this->url, productoBody(['nombre_comercial' => 'Otro']))->assertForbidden()->assertJsonPath('code', 'sin_permiso');

    $ajeno = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($ajeno));
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, ['tipo' => 'magia'])->assertNotFound();
    $pid = DB::table('productos_sanitarios')->value('id');
    $this->patchJson("/api/v1/establecimientos/{$ajeno->id}/productos-sanitarios/{$pid}", ['row_version' => 1, 'notas' => 'x'])->assertNotFound();
});
