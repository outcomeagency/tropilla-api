<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/animales";
    Sanctum::actingAs(duenoDe($this->est));
    $this->lote = crearLote($this->est, 'BUB', false, 'Búfalas');
    $this->potrero = crearPotrero($this->est);
    $mk = fn ($i, array $x = []) => animalNuevo([...$x, 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => sprintf('C-%03d', $i)]]]);
    $this->animales = [
        $mk(1, ['lote_id' => $this->lote, 'fecha_nacimiento' => '2019-01-01']),
        $mk(2, ['lote_id' => $this->lote, 'fecha_nacimiento' => '2021-01-01']),
        $mk(3, ['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true]),
        $mk(4, ['especie' => 'BOV', 'categoria' => 'BOV_VACA']),
        $mk(5, ['categoria' => 'BUB_BUCERRA']),
    ];
    $this->animales[0]['identificaciones'][] = ['tipo' => 'rfid', 'valor' => '032000123456789'];
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", altaBody($this->animales))->assertCreated();
    $id = fn ($i) => $this->animales[$i]['animal_id'];
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", ['tipo' => 'hacienda.traslado', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$id(2)]],
        'payload' => ['potrero_destino_id' => $this->potrero], 'ocurrido_en' => '2026-03-02'])->assertCreated();
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", ['tipo' => 'sanidad.tratamiento', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$id(2)]],
        'payload' => ['diagnostico_presuntivo' => 'Pietín', 'retiro_carne_dias' => 30], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", ['tipo' => 'reproduccion.tacto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$id(1)]],
        'payload' => ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 100], 'ocurrido_en' => '2026-09-01'])->assertCreated();
});

it('busca por cualquier identificador, parcial y normalizado (caravana, RFID)', function () {
    $this->getJson("{$this->url}?q=c-00")->assertOk()->assertJsonCount(5, 'data');
    $this->getJson("{$this->url}?q=003")->assertJsonCount(1, 'data')->assertJsonPath('data.0.identificacion_principal', 'C-003');
    $this->getJson("{$this->url}?q=0001234567")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->animales[0]['animal_id']);
    $this->getJson("{$this->url}?q=zzz")->assertJsonCount(0, 'data');
});

it('filtra por especie, categoría, lote, potrero, sexo, estado reproductivo, retiro y sugerencia', function () {
    $this->getJson("{$this->url}?especie=BOV")->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?categoria=BUB_BUFALA")->assertJsonCount(2, 'data');
    $this->getJson("{$this->url}?lote_id={$this->lote}")->assertJsonCount(2, 'data');
    $this->getJson("{$this->url}?potrero_id={$this->potrero}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.potrero.nombre', 'El Bajo');
    $this->getJson("{$this->url}?sexo=M")->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?estado_reproductivo=prenada")->assertJsonCount(1, 'data');
    $this->getJson("{$this->url}?sanidad=retiro")->assertJsonCount(1, 'data')->assertJsonPath('data.0.retiro_vigente', true);
    $this->getJson("{$this->url}?sugerencia_categoria=true")->assertOk();
});

it('ordena y pagina server-side con cursor opaco; cambiar filtros invalida el cursor', function () {
    $p1 = $this->getJson("{$this->url}?limit=2&orden=identificacion")->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.identificacion_principal', 'C-001')->assertJsonPath('meta.limit', 2);
    $p2 = $this->getJson("{$this->url}?limit=2&orden=identificacion&cursor={$p1->json('meta.next_cursor')}")->assertOk()
        ->assertJsonPath('data.0.identificacion_principal', 'C-003');
    $this->getJson("{$this->url}?limit=2&orden=identificacion&cursor={$p2->json('meta.next_cursor')}")->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);

    $this->getJson("{$this->url}?limit=2&orden=peso&cursor={$p1->json('meta.next_cursor')}")->assertStatus(400)->assertJsonPath('code', 'request_invalido');
    $this->getJson("{$this->url}?orden=edad")->assertJsonPath('data.0.identificacion_principal', 'C-001');
});

it('estado: por defecto solo activos; `todos` incluye bajas', function () {
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", ['tipo' => 'hacienda.muerte', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->animales[4]['animal_id']]],
        'payload' => ['causa' => 'predador'], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    $this->getJson($this->url)->assertJsonCount(4, 'data');
    $this->getJson("{$this->url}?estado=todos")->assertJsonCount(5, 'data');
    $this->getJson("{$this->url}?estado=muerto")->assertJsonCount(1, 'data');
});

it('lectura puede listar; otro establecimiento 404', function () {
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson($this->url)->assertOk();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson($this->url)->assertNotFound();
    expect(DB::table('animales_estado')->count())->toBe(5);
});
