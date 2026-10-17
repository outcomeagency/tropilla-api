<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-08-10 12:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $bov = fn (string $car, array $extra = []) => animalNuevo(['especie' => 'BOV', 'sexo' => 'M', 'categoria' => 'BOV_TERNERO',
        'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => $car]], ...$extra]);
    $this->t1 = $bov('0101');
    $this->t2 = $bov('0102');
    $this->t3 = $bov('0103', ['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0103'], ['tipo' => 'rfid', 'valor' => '032000000000103']]]);
    $this->t4 = $bov('0104', ['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0104'], ['tipo' => 'rfid', 'valor' => '032000000000104']]]);
    $this->bufala = animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0105']]]);
    $this->postJson("{$this->base}/eventos", altaBody([$this->t1, $this->t2, $this->t3, $this->t4], '2026-04-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", altaBody([$this->bufala], '2026-04-01'))->assertCreated();
});

function importacionBody(array $filas, array $extra = []): array
{
    return [
        'especie' => 'BOV', 'fecha' => '2026-08-10', 'motivo' => 'destete', 'origen' => 'archivo', 'dispositivo_tipo' => 'boton',
        'lector' => 'Bastón RS420', 'archivo_nombre' => 'lecturas.csv', 'estado' => 'abierta',
        'filas' => array_map(fn ($f, $i) => ['numero' => $i + 1, 'origen' => 'archivo', 'estado' => 'lista', ...$f], $filas, array_keys($filas)), ...$extra,
    ];
}

it('concilia cada fila sin descartar ninguna: lista, reposición, sin cambios, duplicada, inválida, en uso, sin conciliar y otra especie', function () {
    $id = (string) Str::uuid7();
    $r = $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", importacionBody([
        ['rfid' => '032 000000000201', 'visual' => '101', 'leido' => '032 000000000201;101'], // ceros a la izquierda: 101 ≡ 0101
        ['rfid' => '032000000000202', 'visual' => '0103'],                                    // ya tiene otro RFID → reposición
        ['rfid' => '032000000000104', 'visual' => '0104'],                                    // ya lo tiene
        ['rfid' => '032000000000201', 'visual' => '0102'],                                    // repetido en el archivo
        ['rfid' => '03200000201', 'visual' => '0102'],                                        // 11 dígitos
        ['rfid' => '032000000000103', 'visual' => '0102'],                                    // vigente en otro animal
        ['rfid' => '032000000000300', 'visual' => '9999'],                                    // no hay animal
        ['rfid' => '032000000000301', 'visual' => '0105'],                                    // es búfala
        ['rfid' => '982000000000302', 'animal_id' => $this->t2['animal_id'], 'estado' => 'lista'], // elegido a mano, prefijo de otro país
        ['rfid' => '032000000000303', 'estado' => 'omitida'],                                 // sin caravana ni animal
    ]))->assertCreated();

    $f = collect($r->json('data.filas'))->keyBy('numero');
    expect($f->pluck('estado_servidor', 'numero')->all())->toBe([
        1 => 'lista', 2 => 'reposicion', 3 => 'sin_cambios', 4 => 'duplicada', 5 => 'invalida', 6 => 'en_uso', 7 => 'sin_conciliar', 8 => 'otra_especie', 9 => 'lista', 10 => 'sin_conciliar',
    ])
        ->and($f[1]['rfid'])->toBe('032000000000201')->and($f[1]['animal_id'])->toBe($this->t1['animal_id'])->and($f[1]['animal_identificacion'])->toBe('0101')
        ->and($f[1]['leido'])->toBe('032 000000000201;101')
        ->and($f[2]['mensajes'][0])->toMatchArray(['codigo' => 'reposicion', 'nivel' => 'info'])
        ->and($f[4]['mensajes'][0]['mensaje'])->toBe('Repetido: ya está en la fila 1.')
        ->and($f[5]['mensajes'][0]['codigo'])->toBe('rfid_formato')
        ->and($f[6]['mensajes'][0]['mensaje'])->toContain('0103')
        ->and($f[8]['mensajes'][0]['mensaje'])->toBe('Es bubalino; esta importación es de bovino.')
        ->and($f[9]['mensajes'][0])->toMatchArray(['codigo' => 'rfid_prefijo', 'nivel' => 'advertencia'])
        ->and($f[10]['estado'])->toBe('omitida');
    expect($r->json('data.resumen'))->toMatchArray(['total' => 10, 'lista' => 2, 'sin_conciliar' => 2, 'reposicion' => 1])
        ->and($r->json('data.reglas.vigentes'))->toHaveCount(3)
        ->and($r->json('data.reglas.vigentes.2.alcance'))->toBe('todas_categorias')
        ->and($r->json('data.aviso'))->toContain('SIGSA');
    expect(DB::table('identificacion_importacion_filas')->where('importacion_id', $id)->count())->toBe(10);
});

it('el teléfono registra las colocaciones por evento con origen y dispositivo; reintentar el PUT es idempotente y las filas no se borran', function () {
    $id = (string) Str::uuid7();
    $evento = (string) Str::uuid7();
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", importacionBody([['rfid' => '032000000000201', 'visual' => '0101'], ['rfid' => '1', 'visual' => '0102']]))
        ->assertCreated();

    // El evento llega por su camino (push o web) con los datos nuevos del payload.
    $this->postJson("{$this->base}/eventos", [
        'id' => $evento, 'tipo' => 'hacienda.identificacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->t1['animal_id']]], 'ocurrido_en' => '2026-08-10',
        'payload' => ['accion' => 'colocacion', 'identificacion' => ['tipo' => 'rfid', 'valor' => '032000000000201', 'colocada_en' => '2026-08-10'],
            'motivo' => 'destete', 'origen' => 'archivo', 'dispositivo' => ['tipo' => 'boton', 'lector' => 'Bastón RS420'],
            'vinculada_a' => ['tipo' => 'caravana_visual', 'valor' => '0101'], 'importacion_id' => $id],
    ])->assertCreated();
    $vigente = DB::table('animal_identificaciones')->where('animal_id', $this->t1['animal_id'])->where('tipo', 'rfid')->where('vigente', true)->first();
    expect($vigente->valor)->toBe('032000000000201')->and($vigente->colocada_en)->toBe('2026-08-10');

    $body = importacionBody([['rfid' => '032000000000201', 'visual' => '0101', 'evento_id' => $evento], ['rfid' => '1', 'visual' => '0102']], ['estado' => 'confirmada']);
    $r = $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", $body)->assertOk();
    expect($r->json('data.estado'))->toBe('confirmada')->and($r->json('data.confirmada_en'))->not->toBeNull()
        ->and($r->json('data.filas.0.estado_servidor'))->toBe('registrada')->and($r->json('data.filas.0.evento_id'))->toBe($evento)
        ->and($r->json('data.filas.1.estado_servidor'))->toBe('invalida');
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", $body)->assertOk();

    // Confirmada: la cabecera no cambia; las filas no se borran.
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", [...$body, 'especie' => 'BUB', 'estado' => 'abierta'])->assertOk()
        ->assertJsonPath('data.especie', 'BOV')->assertJsonPath('data.estado', 'confirmada');
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", [...$body, 'filas' => [$body['filas'][0]]])->assertStatus(422)
        ->assertJsonPath('errors.filas.0', 'Falta la fila 2.');

    $this->getJson("{$this->base}/identificaciones/importaciones")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.filas');
    $this->getJson("{$this->base}/identificaciones/importaciones?estado=abierta")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/identificaciones/importaciones/{$id}")->assertOk()->assertJsonCount(2, 'data.filas');

    // Payload de identificación: valores fuera de catálogo no pasan.
    $this->postJson("{$this->base}/eventos", [
        'tipo' => 'hacienda.identificacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->t2['animal_id']]], 'ocurrido_en' => '2026-08-10',
        'payload' => ['accion' => 'colocacion', 'identificacion' => ['tipo' => 'rfid', 'valor' => '032000000000210'], 'origen' => 'telepatia'],
    ])->assertStatus(422);
});

it('reglas por especie y fecha: antes de julio 2026 el hito de todas las categorías es próximo; bubalinos tienen los mismos hitos como datos', function () {
    $r = $this->putJson("{$this->base}/identificaciones/importaciones/".Str::uuid7(), importacionBody([], ['especie' => 'BUB', 'fecha' => '2026-05-02']))->assertCreated();
    expect(collect($r->json('data.reglas.vigentes'))->pluck('alcance')->all())->toBe(['destete_primer_movimiento', 'stock_sin_oficial'])
        ->and($r->json('data.reglas.proximos.0'))->toMatchArray(['desde' => '2026-07-01', 'alcance' => 'todas_categorias']);
    $cat = $this->getJson('/api/v1/sync/catalogos')->assertOk()->json('data');
    expect(collect($cat['parametros'])->where('clave', 'identificacion_electronica_hitos')->pluck('especie')->sort()->values()->all())->toBe(['BOV', 'BUB'])
        ->and(collect($cat['tipos_documento'])->pluck('codigo'))->toContain('planilla_identificacion');
    $schema = collect($cat['tipos_evento'])->firstWhere('codigo', 'hacienda.identificacion')['json_schema'];
    expect(array_keys($schema['properties']))->toContain('motivo', 'origen', 'dispositivo', 'vinculada_a', 'importacion_id');
});

it('permisos, pertenencia y validación', function () {
    $id = (string) Str::uuid7();
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", importacionBody([]))->assertForbidden();
    $this->getJson("{$this->base}/identificaciones/importaciones")->assertOk();

    Sanctum::actingAs($this->dueno);
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", importacionBody([['rfid' => '1']], ['especie' => 'OVI', 'motivo' => 'x']))->assertStatus(422)
        ->assertJsonValidationErrors(['especie', 'motivo']);
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", [...importacionBody([]), 'filas' => [['numero' => 1, 'origen' => 'archivo', 'estado' => 'lista'], ['numero' => 1, 'origen' => 'archivo', 'estado' => 'x']]])
        ->assertStatus(422)->assertJsonValidationErrors(['filas.0.numero', 'filas.1.estado']);
    $this->putJson("{$this->base}/identificaciones/importaciones/no-es-uuid", importacionBody([]))->assertNotFound();
    $this->putJson("{$this->base}/identificaciones/importaciones/{$id}", importacionBody([]))->assertCreated();

    // Otro establecimiento: ni lo ve ni lo puede pisar con el mismo id.
    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("{$this->base}/identificaciones/importaciones/{$id}")->assertNotFound();
    $this->putJson("/api/v1/establecimientos/{$otro->id}/identificaciones/importaciones/{$id}", importacionBody([]))->assertNotFound();
    $this->getJson("/api/v1/establecimientos/{$otro->id}/identificaciones/importaciones/{$id}")->assertNotFound();
});
