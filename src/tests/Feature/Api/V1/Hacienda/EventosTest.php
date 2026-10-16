<?php

use App\Domain\Hacienda\Models\Evento;
use App\Domain\Hacienda\Proyecciones\Proyector;
use App\Domain\Integridad\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/eventos";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));
});

function estado(string $animalId): object
{
    return DB::table('animales_estado')->where('animal_id', $animalId)->first();
}

it('alta individual: evento encadenado, animal proyectado, identificación y existencias', function () {
    $a = animalNuevo(['raza' => 'BUB_MURRAH', 'fecha_nacimiento' => '2020-05-01', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'U-0142']]]);

    $res = $this->postJson($this->url, altaBody([$a]))->assertCreated()
        ->assertJsonPath('data.tipo', 'hacienda.alta')->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.vigente', true)->assertJsonPath('data.confianza', 'declarado')
        ->assertJsonPath('data.origen', 'web')->assertJsonPath('data.seq', 1)
        ->assertJsonPath('data.autor.rol', 'encargado');

    $e = Evento::query()->sole();
    expect($res->json('data.huella'))->toBe(CanonicalJson::huella(CanonicalJson::decode($e->sobreCrudo())))
        ->and(DB::table('registros_integridad')->where('sujeto_id', $e->id)->value('seq'))->toBe(1);

    $s = estado($a['animal_id']);
    expect($s->estado)->toBe('activo')->and($s->categoria_codigo)->toBe('BUB_BUFALA')->and($s->identificacion_principal)->toBe('U-0142')
        ->and($s->origen)->toBe('inventario');
    expect(DB::table('existencias')->where('establecimiento_id', $this->est->id)->where('categoria_codigo', 'BUB_BUFALA')->value('cabezas'))->toBe(1);
    expect(DB::table('cambios')->where('establecimiento_id', $this->est->id)->whereIn('entidad', ['evento', 'animal'])->count())->toBe(2);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful(); // recalcula la huella desde el sobre guardado
});

it('lote anónimo: alta por conteo, traslado del lote y ocupación del potrero; recategorización por movimientos', function () {
    $lote = crearLote($this->est);
    $potrero = crearPotrero($this->est);

    $this->postJson($this->url, ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRA' => 12, 'BUB_BUCERRO' => 15]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.traslado', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote],
        'payload' => ['potrero_destino_id' => $potrero], 'ocurrido_en' => '2026-03-02'])->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.recategorizacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote],
        'payload' => ['motivo' => 'destete', 'movimientos' => [['de' => 'BUB_BUCERRA', 'a' => 'BUB_BUBILLA', 'cantidad' => 5]]], 'ocurrido_en' => '2026-04-01'])->assertCreated();

    expect(DB::table('lote_conteos')->where('lote_id', $lote)->pluck('cabezas', 'categoria_codigo')->all())
        ->toEqual(['BUB_BUCERRA' => 7, 'BUB_BUCERRO' => 15, 'BUB_BUBILLA' => 5])
        ->and(DB::table('lotes_estado')->where('lote_id', $lote)->value('potrero_id'))->toBe($potrero)
        ->and(DB::table('existencias')->where('establecimiento_id', $this->est->id)->sum('cabezas'))->toBe(27);

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/potreros/{$potrero}")->assertOk()
        ->assertJsonPath('data.ocupacion.cabezas', 27)->assertJsonPath('data.ocupacion.lotes.0.id', $lote);
});

it('pesajes: GDP entre pesajes y advertencia de pesaje atípico (requiere confirmación en la web)', function () {
    $a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true]);
    $this->postJson($this->url, altaBody([$a], '2026-01-01'))->assertCreated();
    $pesaje = fn ($kg, $fecha) => ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
        'payload' => ['modo' => 'individual', 'peso_kg' => $kg, 'metodo' => 'balanza'], 'ocurrido_en' => $fecha];

    $this->postJson($this->url, $pesaje('400', '2026-02-01'))->assertCreated();
    $this->postJson($this->url, $pesaje('430', '2026-03-03'))->assertCreated();
    expect(estado($a['animal_id']))->peso_kg->toBe('430.0')->gdp_kg_dia->toBe('1.000');

    $this->postJson($this->url, $pesaje('600', '2026-03-10'))->assertStatus(422)
        ->assertJsonPath('code', 'requiere_confirmacion')->assertJsonPath('advertencias.0.codigo', 'pesaje_atipico');
    $this->postJson($this->url, [...$pesaje('600', '2026-03-10'), 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.observaciones_validacion', ['pesaje_atipico']);
});

it('bajas: muerte da de baja y un evento posterior a la baja se bloquea online', function () {
    $a = animalNuevo();
    $this->postJson($this->url, altaBody([$a]))->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.muerte', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
        'payload' => ['causa' => 'ahogamiento'], 'ocurrido_en' => '2026-05-01'])->assertCreated();

    expect(estado($a['animal_id']))->estado->toBe('muerto')->baja_en->toBe('2026-05-01');
    expect(DB::table('existencias')->where('establecimiento_id', $this->est->id)->count())->toBe(0);

    $this->postJson($this->url, ['tipo' => 'hacienda.condicion_corporal', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
        'payload' => ['valor' => '5', 'escala' => '1-9'], 'ocurrido_en' => '2026-05-10'])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
});

it('reglas como datos: parto en macho, lote de otra especie y categoría de otra especie se bloquean', function () {
    $toro = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUFALO_TORO']);
    $this->postJson($this->url, altaBody([$toro]))->assertCreated();

    $this->postJson($this->url, ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$toro['animal_id']]],
        'payload' => ['tipo' => 'normal', 'crias' => []], 'ocurrido_en' => '2026-06-01'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $loteBov = crearLote($this->est, 'BOV', false, 'Vacas');
    $this->postJson($this->url, ['tipo' => 'hacienda.cambio_lote', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$toro['animal_id']]],
        'payload' => ['lote_destino_id' => $loteBov], 'ocurrido_en' => '2026-06-01'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $this->postJson($this->url, altaBody([animalNuevo(['categoria' => 'BOV_VACA'])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, altaBody([animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUFALA'])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
});

it('parto de búfala: crea las crías con madre y la FPP usa la gestación de la raza', function () {
    $madre = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson($this->url, altaBody([$madre], '2025-01-01'))->assertCreated();
    $this->postJson($this->url, ['tipo' => 'reproduccion.inseminacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$madre['animal_id']]],
        'payload' => ['tipo' => 'ia'], 'ocurrido_en' => '2025-03-01'])->assertCreated();
    expect(estado($madre['animal_id']))->estado_reproductivo->toBe('servida')->fecha_probable_parto->toBe('2025-12-29'); // + 303 d (Murrah)

    $cria = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO']);
    $this->postJson($this->url, ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$madre['animal_id']]],
        'payload' => ['tipo' => 'normal', 'crias' => [['viva' => true, 'animal' => $cria, 'peso_kg' => '38']]], 'ocurrido_en' => '2025-12-27'])->assertCreated();

    expect(estado($madre['animal_id']))->partos->toBe(1)->estado_reproductivo->toBe('parida');
    expect(estado($cria['animal_id']))->madre_id->toBe($madre['animal_id'])->origen->toBe('nacido')->fecha_nacimiento->toBe('2025-12-27');
});

it('422 por JSON Schema del payload y por sujetos mal formados', function () {
    $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [(string) Str::uuid7()]],
        'payload' => ['modo' => 'individual', 'peso_kg' => 412.5, 'metodo' => 'balanza'], 'ocurrido_en' => '2026-01-01'])->assertStatus(422);
    $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [(string) Str::uuid7()]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '412,5'], 'ocurrido_en' => '2026-01-01'])
        ->assertStatus(422)->assertJsonPath('code', 'validacion')->assertJsonStructure(['errors']);
    $this->postJson($this->url, ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'potrero', 'potrero_id' => (string) Str::uuid7()],
        'payload' => ['motivo' => 'alta_rapida'], 'ocurrido_en' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, ['tipo' => 'hacienda.inexistente', 'sujetos' => ['tipo' => 'animales'], 'payload' => [], 'ocurrido_en' => '2026-01-01'])->assertStatus(422);
    $this->postJson($this->url, ['sujetos' => ['tipo' => 'animales']])->assertStatus(422)->assertJsonValidationErrors(['tipo', 'ocurrido_en']);
});

it('identificación duplicada online → 409', function () {
    $this->postJson($this->url, altaBody([animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '1187']]])]))->assertCreated();
    $this->postJson($this->url, altaBody([animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '1187']]])]))
        ->assertStatus(409)->assertJsonPath('code', 'identificacion_duplicada');
});

it('lectura no registra (403) y otro establecimiento da 404', function () {
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson($this->url, altaBody([animalNuevo()]))->assertForbidden()->assertJsonPath('code', 'sin_permiso');
    $this->getJson($this->url)->assertOk();

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson($this->url, altaBody([animalNuevo()]))->assertNotFound();
});

it('POST con id es idempotente (reintento → 200 con el mismo evento)', function () {
    $id = (string) Str::uuid7();
    $body = [...altaBody([animalNuevo()]), 'id' => $id];
    $this->postJson($this->url, $body)->assertCreated()->assertJsonPath('data.id', $id);
    $this->postJson($this->url, $body)->assertOk()->assertJsonPath('data.id', $id);
    expect(Evento::query()->count())->toBe(1);
});

it('eventos es append-only en el modelo y en la base', function () {
    $this->postJson($this->url, altaBody([animalNuevo()]))->assertCreated();
    $e = Evento::query()->sole();

    expect(fn () => $e->forceFill(['motivo' => 'x'])->save())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::update("UPDATE eventos SET responsable = 'x'")))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::delete('DELETE FROM evento_animales')))->toThrow(QueryException::class, 'append-only');
});

it('reconstruir las proyecciones da exactamente lo mismo que el cálculo en línea', function () {
    $lote = crearLote($this->est);
    $a = animalNuevo(['lote_id' => $lote, 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'A-1']]]);
    $b = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'lote_id' => $lote]);
    $this->postJson($this->url, altaBody([$a, $b]))->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRA' => 3]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$b['animal_id']]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '455.5', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-03-05'])->assertCreated();

    $foto = fn () => [
        DB::table('animales_estado')->orderBy('animal_id')->get()->map(fn ($r) => collect((array) $r)->except('actualizado_en', 'categoria_sugerida_en')->all())->all(),
        DB::table('lote_conteos')->orderBy('lote_id')->orderBy('categoria_codigo')->get()->all(),
        DB::table('existencias')->orderBy('categoria_codigo')->get()->map(fn ($r) => collect((array) $r)->except('actualizado_en')->all())->all(),
        DB::table('animal_identificaciones')->orderBy('animal_id')->get(['animal_id', 'tipo', 'valor', 'vigente'])->all(),
    ];
    $antes = $foto();
    app(Proyector::class)->reconstruir($this->est->id);

    expect($foto())->toEqual($antes);
});

it('actividad del establecimiento paginada por cursor con resumen', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson($this->url, altaBody([animalNuevo()], "2026-0{$i}-01"))->assertCreated();
    }
    $p1 = $this->getJson("{$this->url}?limit=2")->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.ocurrido_en', '2026-03-01')->assertJsonPath('data.0.resumen', 'Alta de 1 animal');
    $this->getJson("{$this->url}?limit=2&cursor={$p1->json('meta.next_cursor')}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?tipo=hacienda.pesaje")->assertOk()->assertJsonCount(0, 'data');
});

it('interdicción declarada por evento aparece en el establecimiento y advierte en egresos', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->postJson($this->url, ['tipo' => 'establecimiento.interdiccion', 'sujetos' => ['tipo' => 'establecimiento'],
        'payload' => ['motivo' => 'rabia', 'inicio' => '2026-04-01'], 'ocurrido_en' => '2026-04-01'])->assertCreated();
    $this->getJson("/api/v1/establecimientos/{$this->est->id}")->assertJsonPath('data.interdiccion_vigente.motivo', 'rabia');

    $a = animalNuevo();
    $this->postJson($this->url, altaBody([$a]))->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
        'payload' => ['motivo' => 'venta', 'destino' => 'invernada'], 'ocurrido_en' => '2026-04-10'])
        ->assertStatus(422)->assertJsonPath('advertencias.0.codigo', 'interdiccion_vigente');
});
