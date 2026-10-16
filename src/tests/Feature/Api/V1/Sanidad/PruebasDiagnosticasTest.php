<?php

use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->vet = miembro($this->est, 'asesor'));
    $this->bufala = animalNuevo();
    $this->vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $this->postJson("{$this->base}/eventos", altaBody([$this->bufala, $this->vaca], '2026-01-10'))->assertCreated();
});

function tuberculina(string $animalId, array $payload): array
{
    return ['tipo' => 'sanidad.prueba_diagnostica', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$animalId]],
        'payload' => ['prueba' => 'tuberculina_anocaudal', 'laboratorio' => 'Vet. Pérez', ...$payload], 'ocurrido_en' => '2026-09-03'];
}

it('tuberculina anocaudal: resultado calculado 0 h / 72 h con umbrales por parámetro (negativo, sospechoso, positivo → reactor)', function () {
    $id = $this->bufala['animal_id'];
    $neg = $this->postJson("{$this->base}/eventos", tuberculina($id, ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '5.5']))->assertCreated();
    $neg->assertJsonPath('data.payload.resultado', 'negativo')->assertJsonPath('data.payload.resultado_origen', 'calculado')->assertJsonPath('data.payload.umbrales_version', 1);

    $this->postJson("{$this->base}/eventos", tuberculina($id, ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '7.5']))->assertCreated()
        ->assertJsonPath('data.payload.resultado', 'sospechoso');
    expect(DB::table('animales_estado')->where('animal_id', $id)->value('reactor'))->toBeFalse();

    // Positivo: la web pide confirmar que pasa a reactor.
    $pos = tuberculina($id, ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '9']);
    $r = $this->postJson("{$this->base}/eventos", $pos)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['marca_reactor']);
    $this->postJson("{$this->base}/eventos", [...$pos, 'confirmar_advertencias' => true])->assertCreated()->assertJsonPath('data.payload.resultado', 'positivo');
    $e = DB::table('animales_estado')->where('animal_id', $id)->first();
    expect($e->reactor)->toBeTrue()->and($e->reactor_prueba)->toBe('tuberculina_anocaudal')->and($e->reactor_desde)->toBe('2026-09-03');

    // El vet ajusta el umbral de positivo para búfalos de este establecimiento: Δ 5 deja de ser positivo.
    Sanctum::actingAs(duenoDe($this->est));
    $this->putJson("{$this->base}/parametros/tuberculina_anocaudal_positivo_gte_mm", ['valor' => '6', 'especie' => 'BUB', 'motivo' => 'Criterio del Dr. Pérez'])->assertOk();
    $this->postJson("{$this->base}/eventos", tuberculina($id, ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '9']))->assertCreated()
        ->assertJsonPath('data.payload.resultado', 'sospechoso');
    // …la vaca sigue con el umbral global.
    $this->postJson("{$this->base}/eventos", [...tuberculina($this->vaca['animal_id'], ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '9']), 'confirmar_advertencias' => true])
        ->assertCreated()->assertJsonPath('data.payload.resultado', 'positivo');
});

it('cervical simple sin sospechoso; declarado distinto al calculado advierte; calculado incoherente 422; resumen', function () {
    $id = $this->vaca['animal_id'];
    $this->postJson("{$this->base}/eventos", [...tuberculina($id, ['prueba' => 'tuberculina_cervical_simple', 'lectura_0h_mm' => '3', 'lectura_72h_mm' => '5.9']),
        'confirmar_advertencias' => true])->assertCreated()->assertJsonPath('data.payload.resultado', 'negativo');
    $this->postJson("{$this->base}/eventos", [...tuberculina($id, ['prueba' => 'tuberculina_cervical_simple', 'lectura_0h_mm' => '3', 'lectura_72h_mm' => '6']),
        'confirmar_advertencias' => true])->assertCreated()->assertJsonPath('data.payload.resultado', 'positivo');

    $r = $this->postJson("{$this->base}/eventos", tuberculina($this->bufala['animal_id'], ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '5', 'resultado' => 'sospechoso', 'resultado_origen' => 'declarado']))
        ->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['resultado_distinto_al_calculado']);

    $this->postJson("{$this->base}/eventos", tuberculina($this->bufala['animal_id'], ['lectura_0h_mm' => '4', 'lectura_72h_mm' => '5', 'resultado' => 'positivo', 'resultado_origen' => 'calculado']))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", tuberculina($this->bufala['animal_id'], ['resultado' => 'negativo', 'resultado_origen' => 'calculado']))
        ->assertStatus(422)->assertJsonValidationErrors(['payload.resultado_origen']);
    $this->postJson("{$this->base}/eventos", tuberculina($this->bufala['animal_id'], ['prueba' => 'no_existe', 'resultado' => 'negativo', 'resultado_origen' => 'declarado']))
        ->assertStatus(422)->assertJsonValidationErrors(['payload.prueba']);
    // Lecturas en mm: un solo animal.
    $ids = [$this->bufala['animal_id'], $this->vaca['animal_id']];
    sort($ids);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.prueba_diagnostica', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['prueba' => 'tuberculina_anocaudal', 'lectura_0h_mm' => '4', 'lectura_72h_mm' => '5', 'resultado' => 'negativo', 'resultado_origen' => 'declarado'], 'ocurrido_en' => '2026-09-03'])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $this->getJson("{$this->base}/animales/{$id}/eventos?modulo=sanidad")->assertJsonPath('data.0.resumen', 'tuberculina_cervical_simple: positivo');
});

it('análisis de laboratorio con resultado y archivo adjunto; 403 lectura; 404 ajeno', function () {
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.analisis_laboratorio', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->bufala['animal_id']]],
        'payload' => ['tipo' => 'coprologico', 'muestra' => 'Materia fecal', 'laboratorio' => 'FCV-UNNE', 'resultado_texto' => 'HPG 450, Toxocara negativo'],
        'adjuntos' => [str_repeat('a', 64)],
        'ocurrido_en' => '2026-09-05'])->assertCreated()->assertJsonPath('data.payload.tipo', 'coprologico')->assertJsonPath('data.adjuntos.0.sha256', str_repeat('a', 64))->assertJsonPath('data.adjuntos.0.estado', 'pendiente');

    $ok = tuberculina($this->bufala['animal_id'], ['resultado' => 'negativo', 'resultado_origen' => 'declarado']);
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/eventos", $ok)->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("{$this->base}/eventos", $ok)->assertNotFound();
});

it('por sync: un calculado con umbrales viejos se acepta con revisión (los umbrales pudieron cambiar offline)', function () {
    $disp = (string) Str::uuid7();
    DB::table('dispositivos')->insert(['id' => $disp, 'usuario_id' => $this->vet->id, 'plataforma' => 'android', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($this->vet, ['app']);
    $id = (string) Str::uuid7();
    $sobre = json_decode(json_encode([
        'esquema' => 'tropilla.evento/1', 'id' => $id, 'establecimiento_id' => $this->est->id, 'evento_raiz_id' => $id, 'version' => 1, 'accion' => 'alta',
        'tipo' => 'sanidad.prueba_diagnostica', 'tipo_version' => 1, 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->bufala['animal_id']]],
        'payload' => ['prueba' => 'tuberculina_anocaudal', 'lectura_0h_mm' => '4', 'lectura_72h_mm' => '8', 'resultado' => 'positivo', 'resultado_origen' => 'calculado', 'umbrales_version' => 1],
        'ocurrido_en' => '2026-09-03', 'creado_en' => '2026-09-03T12:00:00.000Z', 'autor_id' => $this->vet->id, 'dispositivo_id' => $disp, 'origen' => 'app',
    ]));
    $r = $this->postJson('/api/v1/sync/push', ['establecimiento_id' => $this->est->id, 'dispositivo_id' => $disp, 'envio_id' => (string) Str::uuid7(),
        'items' => [['clase' => 'evento', 'sobre' => $sobre, 'huella' => CanonicalJson::huella($sobre)]]])->assertOk()->json('data.resultados.0');
    expect($r['estado'])->toBe('aceptado')->and($r['observaciones'])->toContain('resultado_prueba_discrepante', 'marca_reactor')
        ->and(DB::table('revisiones')->where('tipo', 'resultado_prueba_discrepante')->count())->toBe(1)
        ->and(DB::table('animales_estado')->where('animal_id', $this->bufala['animal_id'])->value('reactor'))->toBeTrue();
});
