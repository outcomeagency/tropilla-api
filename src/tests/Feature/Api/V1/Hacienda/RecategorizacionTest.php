<?php

use App\Domain\Hacienda\Proyecciones\CondicionEvaluator;
use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
});

function sugerida(string $id): ?string
{
    return DB::table('animales_estado')->where('animal_id', $id)->value('categoria_sugerida_codigo');
}

it('reglas por especie: destete → bubilla/vaquillona, castración, parto → vaca/búfala, dentición del búfalo antes que la del bovino', function () {
    $bucerra = animalNuevo(['categoria' => 'BUB_BUCERRA', 'fecha_nacimiento' => now()->subMonths(5)->toDateString()]);
    $ternera = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_TERNERA', 'fecha_nacimiento' => now()->subMonths(5)->toDateString()]);
    $bubillito = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLITO', 'castrado' => true, 'fecha_nacimiento' => now()->subMonths(17)->toDateString()]);
    $novillito = animalNuevo(['especie' => 'BOV', 'sexo' => 'M', 'categoria' => 'BOV_NOVILLITO', 'castrado' => true, 'fecha_nacimiento' => now()->subMonths(17)->toDateString()]);
    $bubilla = animalNuevo(['categoria' => 'BUB_BUBILLA']);
    $this->postJson("{$this->base}/eventos", altaBody([$bucerra, $ternera, $bubillito, $novillito, $bubilla], now()->subMonths(1)->toDateString()))->assertCreated();

    expect(sugerida($bucerra['animal_id']))->toBeNull()->and(sugerida($ternera['animal_id']))->toBeNull();
    foreach ([$bucerra, $ternera] as $a) {
        $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
            'payload' => ['tipo' => 'tradicional'], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    }
    expect(sugerida($bucerra['animal_id']))->toBe('BUB_BUBILLA')->and(sugerida($ternera['animal_id']))->toBe('BOV_VAQUILLONA')
        // 17 meses: el búfalo ya cambió dientes (16 m), el bovino no (23 m) — mismo DSL, parámetros distintos
        ->and(sugerida($bubillito['animal_id']))->toBe('BUB_BUBILLO')->and(sugerida($novillito['animal_id']))->toBeNull();

    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$bubilla['animal_id']]],
        'payload' => ['tipo' => 'normal', 'crias' => [['viva' => false, 'sexo' => 'M']]], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    expect(sugerida($bubilla['animal_id']))->toBe('BUB_BUFALA');

    $this->getJson("{$this->base}/animales?sugerencia_categoria=true")->assertJsonCount(4, 'data');
});

it('confirmar en bloque: un evento por animal con sugerida/regla y la sugerencia desaparece', function () {
    $ids = [];
    foreach (range(1, 3) as $i) {
        $a = animalNuevo(['categoria' => 'BUB_BUCERRA']);
        $ids[] = $a['animal_id'];
        $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
        $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
            'payload' => ['tipo' => 'tradicional'], 'ocurrido_en' => '2026-04-01'])->assertCreated();
    }

    $res = $this->postJson("{$this->base}/animales/recategorizar", ['items' => [
        ['animal_id' => $ids[0], 'categoria' => 'BUB_BUBILLA'],
        ['animal_id' => $ids[1], 'categoria' => 'BUB_BUBILLA'],
        ['animal_id' => $ids[2], 'categoria' => 'BUB_BUFALA'], // manual
    ]])->assertCreated()->assertJsonCount(3, 'data')->assertJsonPath('data.0.tipo', 'hacienda.recategorizacion');

    $payloads = DB::table('eventos')->where('tipo', 'hacienda.recategorizacion')->orderBy('id')->pluck('payload')->map(fn ($p) => json_decode($p, true))->all();
    expect($payloads[0])->toMatchArray(['sugerida' => true, 'motivo' => 'destete'])->and($payloads[0]['regla_id'])->toBeInt()
        ->and($payloads[2])->toMatchArray(['sugerida' => false, 'motivo' => 'manual']);
    expect(sugerida($ids[0]))->toBeNull()->and(DB::table('animales_estado')->where('animal_id', $ids[2])->value('categoria_codigo'))->toBe('BUB_BUFALA');
    expect($res->json('data'))->toHaveCount(3);
});

it('todo o nada: una categoría de otra especie invalida el bloque (422); 403 lectura', function () {
    $a = animalNuevo(['categoria' => 'BUB_BUCERRA']);
    $b = animalNuevo(['categoria' => 'BUB_BUCERRA']);
    $this->postJson("{$this->base}/eventos", altaBody([$a, $b]))->assertCreated();
    $this->postJson("{$this->base}/animales/recategorizar", ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUBILLA'], ['animal_id' => $b['animal_id'], 'categoria' => 'BOV_VAQUILLONA']]])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    expect(DB::table('eventos')->where('tipo', 'hacienda.recategorizacion')->count())->toBe(0);
    $this->postJson("{$this->base}/animales/recategorizar", ['items' => []])->assertStatus(422);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/animales/recategorizar", ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUBILLA']]])->assertForbidden();
});

it('el job diario recalcula sugerencias que dependen de la edad', function () {
    $toro = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUTORITO', 'castrado' => false, 'fecha_nacimiento' => now()->subMonths(29)->toDateString()]);
    $this->postJson("{$this->base}/eventos", altaBody([$toro]))->assertCreated();
    expect(sugerida($toro['animal_id']))->toBeNull();

    $this->travel(2)->months();
    $this->artisan('hacienda:sugerir-categorias')->assertSuccessful();
    expect(sugerida($toro['animal_id']))->toBe('BUB_BUFALO_TORO');
});

it('evaluador del DSL: todas/alguna/no, parámetros y nulls', function () {
    $p = fn ($c) => ['umbral' => 16][$c] ?? null;
    expect(CondicionEvaluator::evaluar(['todas' => [['campo' => 'sexo', 'op' => 'eq', 'valor' => 'M'], ['alguna' => [['campo' => 'edad', 'op' => 'gte', 'valor' => ['param' => 'umbral']], ['campo' => 'd', 'op' => 'in', 'valor' => ['4']]]]]], ['sexo' => 'M', 'edad' => 17], $p))->toBeTrue()
        ->and(CondicionEvaluator::evaluar(['campo' => 'edad', 'op' => 'gte', 'valor' => 10], ['edad' => null], $p))->toBeFalse()
        ->and(CondicionEvaluator::evaluar(['no' => ['campo' => 'x', 'op' => 'existe']], [], $p))->toBeTrue()
        ->and(CondicionEvaluator::evaluar(['param' => 'umbral', 'op' => 'eq', 'valor' => 16], [], $p))->toBeTrue();
});

it('una cría dada de alta por push ya pasada la edad de destete recibe sugerencia (sin evento de destete)', function () {
    $disp = (string) Str::uuid7();
    $user = miembro($this->est, 'encargado');
    DB::table('dispositivos')->insert(['id' => $disp, 'usuario_id' => $user->id, 'plataforma' => 'android', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($user, ['app']);
    $bucerro = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'castrado' => false, 'fecha_nacimiento' => now()->subMonths(24)->toDateString()]);
    $chico = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'castrado' => false, 'fecha_nacimiento' => now()->subMonths(3)->toDateString()]);
    $id = (string) Str::uuid7();
    $sobre = json_decode(json_encode(['esquema' => 'tropilla.evento/1', 'id' => $id, 'establecimiento_id' => $this->est->id, 'evento_raiz_id' => $id,
        'version' => 1, 'accion' => 'alta', 'tipo' => 'hacienda.alta', 'tipo_version' => 1,
        'sujetos' => ['tipo' => 'animales', 'animal_ids' => array_values(Arr::sort([$bucerro['animal_id'], $chico['animal_id']]))],
        'payload' => ['motivo' => 'alta_rapida', 'animales' => [$bucerro, $chico]], 'ocurrido_en' => now()->toDateString(),
        'creado_en' => '2026-10-15T12:31:07.250Z', 'autor_id' => $user->id, 'dispositivo_id' => $disp, 'origen' => 'app']));
    $this->postJson('/api/v1/sync/push', ['establecimiento_id' => $this->est->id, 'dispositivo_id' => $disp, 'envio_id' => (string) Str::uuid7(),
        'items' => [['clase' => 'evento', 'sobre' => $sobre, 'huella' => CanonicalJson::huella($sobre)]]])->assertOk()->assertJsonPath('data.resultados.0.estado', 'aceptado');

    expect(sugerida($bucerro['animal_id']))->toBe('BUB_BUTORITO')->and(sugerida($chico['animal_id']))->toBeNull();
    // A los 7 meses (parámetro edad_destete_meses) el job diario la sugiere también al chico.
    $this->travel(4)->months();
    $this->artisan('hacienda:sugerir-categorias')->assertSuccessful();
    expect(sugerida($chico['animal_id']))->toBe('BUB_BUTORITO');
});

it('descartar sugerencia: deja de sugerirse para ese animal (también en el job y al proyectar), idempotente', function () {
    $ids = [];
    foreach (range(1, 2) as $i) {
        $a = animalNuevo(['categoria' => 'BUB_BUCERRA']);
        $ids[] = $a['animal_id'];
        $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
        $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
            'payload' => ['tipo' => 'tradicional'], 'ocurrido_en' => '2026-04-01'])->assertCreated();
    }
    $url = "{$this->base}/animales/sugerencias/descartar";
    $this->postJson($url, ['items' => [['animal_id' => $ids[0], 'categoria' => 'BUB_BUBILLA']]])->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.0.categoria_sugerida', null);
    $this->postJson($url, ['items' => [['animal_id' => $ids[0], 'categoria' => 'BUB_BUBILLA']]])->assertOk(); // idempotente

    $this->getJson("{$this->base}/animales?sugerencia_categoria=true")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[1]);
    $this->getJson("{$this->base}/animales/{$ids[0]}")->assertJsonPath('data.categorias_descartadas', ['BUB_BUBILLA']);
    expect(DB::table('auditoria')->where('accion', 'descartar_sugerencia')->where('entidad_id', $ids[0])->count())->toBe(1)
        ->and(DB::table('cambios')->where('entidad', 'animal')->where('entidad_id', $ids[0])->exists())->toBeTrue();

    // Un evento nuevo (reproyección) y el job diario no la vuelven a sugerir; un parto sí sugiere otra categoría.
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$ids[0]]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '180', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-05-01'])->assertCreated();
    $this->artisan('hacienda:sugerir-categorias')->assertSuccessful();
    expect(sugerida($ids[0]))->toBeNull();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$ids[0]]],
        'payload' => ['tipo' => 'normal', 'crias' => [['viva' => false, 'sexo' => 'M']]], 'ocurrido_en' => '2026-06-01'])->assertCreated();
    expect(sugerida($ids[0]))->toBe('BUB_BUFALA');
});

it('descartar sugerencia: 422 (sugerencia distinta, animal ajeno, vacío) todo o nada; 403 lectura; 404 otro establecimiento', function () {
    $a = animalNuevo(['categoria' => 'BUB_BUCERRA']);
    $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
        'payload' => ['tipo' => 'tradicional'], 'ocurrido_en' => '2026-04-01'])->assertCreated();
    $url = "{$this->base}/animales/sugerencias/descartar";

    $this->postJson($url, ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUBILLA'], ['animal_id' => animalNuevo()['animal_id'], 'categoria' => 'BUB_BUBILLA']]])
        ->assertStatus(422)->assertJsonValidationErrors('items.1.animal_id');
    $this->postJson($url, ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUFALA']]])->assertStatus(422)->assertJsonValidationErrors('items.0.categoria');
    $this->postJson($url, ['items' => []])->assertStatus(422);
    expect(sugerida($a['animal_id']))->toBe('BUB_BUBILLA')->and(DB::table('sugerencias_descartadas')->count())->toBe(0);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson($url, ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUBILLA']]])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson($url, ['items' => [['animal_id' => $a['animal_id'], 'categoria' => 'BUB_BUBILLA']]])->assertNotFound();
});
