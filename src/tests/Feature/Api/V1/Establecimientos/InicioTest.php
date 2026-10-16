<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-20 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
});

it('existencias por especie y categoría con variación del mes, pendientes de la semana y actividad con autor', function () {
    // Septiembre: stock inicial (no es variación de octubre).
    $lote = crearLote($this->est, 'BUB', true, 'Bucerros');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRO' => 10]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-09-01'])->assertCreated();
    $vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $bufala = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$vaca, $bufala], '2026-09-01'))->assertCreated();
    // Octubre: ingreso +2 vacas, muerte de la búfala −1, venta de 3 bucerros, 4 bucerros pasan a bubillos, alta rápida (no cuenta).
    $compradas = [animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']), animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA'])];
    $ids = array_column($compradas, 'animal_id');
    sort($ids);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.ingreso', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['motivo' => 'compra', 'animales' => $compradas], 'ocurrido_en' => '2026-10-02'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.muerte', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$bufala['animal_id']]],
        'payload' => ['causa' => 'rayo'], 'ocurrido_en' => '2026-10-05'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRO' => 3]],
        'payload' => ['motivo' => 'venta', 'destino' => 'invernada'], 'ocurrido_en' => '2026-10-10'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.recategorizacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote],
        'payload' => ['movimientos' => [['de' => 'BUB_BUCERRO', 'a' => 'BUB_BUBILLO', 'cantidad' => 4]], 'motivo' => 'edad'], 'ocurrido_en' => '2026-10-15'])->assertCreated();
    $this->postJson("{$this->base}/animales", ['animal' => ['especie' => 'BOV', 'sexo' => 'M', 'categoria' => 'BOV_TORO']])->assertCreated();

    // Pendientes: tarea en 3 días, parto en 5, documento en 10, consulta nueva y una revisión.
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Vacunar aftosa', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-10-23'])->assertCreated();
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Más adelante', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-12-01'])->assertCreated();
    $prenada = animalNuevo(['raza' => 'BUB_MURRAH', 'categoria' => 'BUB_BUFALA']);
    $this->postJson("{$this->base}/eventos", altaBody([$prenada], '2026-01-10'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.tacto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$prenada['animal_id']]],
        'payload' => ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 298], 'ocurrido_en' => '2026-10-20', 'confirmar_advertencias' => true])->assertCreated();
    $this->postJson("{$this->base}/documentos", ['tipo' => 'certificado_sanitario', 'titulo' => 'Brucelosis', 'vence_en' => '2026-10-30',
        'archivos' => [['sha256' => hash('sha256', 'x'), 'bytes' => 1, 'mime' => 'application/pdf']]])->assertCreated();
    DB::table('consultas')->insert(['id' => (string) Str::uuid7(), 'establecimiento_id' => $this->est->id, 'origen' => 'perfil', 'nombre' => 'Li', 'mensaje' => 'Hola',
        'idioma' => 'en', 'estado' => 'nueva', 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->getJson("{$this->base}/inicio")->assertOk()->json('data');
    $ex = collect($r['existencias'])->keyBy('categoria');
    expect($ex['BOV_VACA'])->toMatchArray(['cabezas' => 3, 'individuales' => 3, 'variacion_mes' => 2])
        ->and($ex['BUB_BUCERRO'])->toMatchArray(['cabezas' => 3, 'anonimas' => 3, 'variacion_mes' => -7])
        ->and($ex['BUB_BUBILLO'])->toMatchArray(['cabezas' => 4, 'variacion_mes' => 4])
        ->and($ex['BUB_BUFALA'])->toMatchArray(['cabezas' => 1, 'variacion_mes' => -1])
        ->and($ex['BOV_TORO']['variacion_mes'])->toBe(0)
        ->and($ex['BUB_BUFALA']['categoria_nombre'])->not->toBe('');
    expect($r['pendientes'])->toMatchArray(['tareas_sanitarias' => 1, 'partos_proximos' => 1, 'partos_vencidos' => 0, 'documentos_por_vencer' => 1,
        'consultas_nuevas' => 1, 'tomas_por_confirmar' => 0])
        ->and(collect($r['pendientes']['items'])->pluck('tipo')->all())->toContain('tarea', 'parto_proximo', 'documento_por_vencer', 'consulta');
    expect($r['actividad'])->toHaveCount(9)->and($r['actividad'][0]['tipo'])->toBe('reproduccion.tacto')
        ->and($r['actividad'][0]['autor']['rol'] ?? $r['actividad'][0]['autor_rol'] ?? null)->not->toBeNull();

    $this->getJson("{$this->base}/inicio?especie=BOV")->assertOk()->assertJsonMissing(['categoria' => 'BUB_BUFALA']);
});

it('por rol: el encargado no ve consultas; 422; 404 ajeno', function () {
    DB::table('consultas')->insert(['id' => (string) Str::uuid7(), 'establecimiento_id' => $this->est->id, 'origen' => 'perfil', 'nombre' => 'Li', 'mensaje' => 'Hola',
        'idioma' => 'en', 'estado' => 'nueva', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/inicio")->assertOk()->assertJsonPath('data.pendientes.consultas_nuevas', 0)->assertJsonPath('data.existencias', []);
    $this->getJson("{$this->base}/inicio?especie=OVI")->assertStatus(422);
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/inicio")->assertNotFound();
});
