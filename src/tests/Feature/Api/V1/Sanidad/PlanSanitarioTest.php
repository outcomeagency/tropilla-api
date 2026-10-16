<?php

use App\Domain\Sanidad\Models\Tarea;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-03-20 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
});

function tareasDe(string $estId, ?string $plantilla = null)
{
    return Tarea::query()->where('tareas.establecimiento_id', $estId)
        ->when($plantilla, fn ($q) => $q->join('plan_items as i', 'i.id', '=', 'tareas.plan_item_id')->where('i.plantilla_codigo', $plantilla))
        ->orderBy('vence_en')->get(['tareas.*']);
}

it('aplicar plantillas exige el aviso veterinario; condiciones por zona; ventanas, relativas a nacimiento y repetición', function () {
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['plantillas' => ['aftosa_campana_1']])->assertStatus(422)->assertJsonValidationErrors(['aviso_aceptado']);
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['plantillas' => ['no_existe'], 'aviso_aceptado' => true])->assertStatus(422)->assertJsonValidationErrors(['plantillas.0']);

    $plan = $this->postJson("{$this->base}/plan-sanitario/plantillas", ['aviso_aceptado' => true,
        'plantillas' => ['aftosa_campana_1', 'rabia_paresiante', 'bub_toxocara_neonatal', 'bub_fasciolicida', 'bub_pediculosis']])->assertOk()
        ->assertJsonPath('data.aviso_aceptado_por.id', $this->dueno->id)->assertJsonCount(5, 'data.items');
    expect($plan->json('data.aviso'))->toContain('veterinario');
    $items = collect($plan->json('data.items'))->keyBy('plantilla_codigo');
    expect($items['aftosa_campana_1'])->toMatchArray(['aplica' => true, 'requiere_configuracion' => true, 'editado' => false])
        ->and($items['rabia_paresiante']['aplica'])->toBeFalse()   // sin zona endémica ni interdicción
        ->and($items['bub_fasciolicida']['aplica'])->toBeFalse()   // sin Fasciola declarada
        ->and($items['bub_pediculosis']['aplica'])->toBeTrue();
    // Reaplicar no duplica.
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['plantillas' => ['aftosa_campana_1'], 'aviso_aceptado' => true])->assertJsonCount(5, 'data.items');

    // Pediculosis abril–junio + repetir a 15 días; aftosa sin fechas todavía no genera.
    $ped = tareasDe($this->est->id, 'bub_pediculosis');
    expect($ped->map(fn ($t) => [$t->fecha_desde->toDateString(), $t->fecha_hasta->toDateString()])->all())->toBe([['2026-04-01', '2026-06-30'], ['2026-04-16', '2026-07-15']])
        ->and(tareasDe($this->est->id, 'aftosa_campana_1'))->toHaveCount(0);

    // Configurar la ventana de aftosa (todo editable) → tarea; el ítem queda «editado».
    $aftosa = $items['aftosa_campana_1'];
    $this->patchJson("{$this->base}/plan-sanitario/items/{$aftosa['id']}", ['row_version' => 1, 'disparador' => ['tipo' => 'ventana', 'desde' => '04-15', 'hasta' => '05-31']])
        ->assertOk()->assertJsonPath('data.editado', true)->assertJsonPath('data.requiere_configuracion', false);
    $t = tareasDe($this->est->id, 'aftosa_campana_1')->sole();
    expect($t->vence_en->toDateString())->toBe('2026-05-31')->and($t->tipo_evento)->toBe('sanidad.vacunacion')->and($t->enfermedad)->toBe('aftosa');

    // Bucerro nacido hace 2 días → antiparasitario neonatal a los 7 días (lo dispara el evento).
    $bucerro = animalNuevo(['categoria' => 'BUB_BUCERRO', 'sexo' => 'M', 'fecha_nacimiento' => '2026-03-18']);
    $this->postJson("{$this->base}/eventos", altaBody([$bucerro], '2026-03-19'))->assertCreated();
    $neo = tareasDe($this->est->id, 'bub_toxocara_neonatal')->sole();
    expect($neo->vence_en->toDateString())->toBe('2026-03-25')->and($neo->alcance)->toBe(['animal_ids' => [$bucerro['animal_id']]])->and($neo->cabezas_estimadas)->toBe(1);

    // Zona de Fasciola declarada → el fasciolicida pasa a aplicar en la próxima generación (abril–mayo).
    DB::table('establecimientos')->where('id', $this->est->id)->update(['zonas_sanitarias' => json_encode(['fasciola_endemica' => true, 'garrapata' => 'control'])]);
    $this->artisan('sanidad:generar-tareas')->assertSuccessful();
    expect(tareasDe($this->est->id, 'bub_fasciolicida')->sole()->fecha_hasta->toDateString())->toBe('2026-05-31');
    $this->artisan('sanidad:generar-tareas')->assertSuccessful(); // idempotente
    expect(tareasDe($this->est->id)->count())->toBe(5);

    // Aplicar con tarea_id la cierra; anular el evento la reabre.
    $ev = $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$bucerro['animal_id']]],
        'payload' => ['producto_nombre' => 'Albendazol', 'tipo' => 'interno', 'tarea_id' => $neo->id], 'ocurrido_en' => '2026-03-25'])->assertCreated();
    $this->getJson("{$this->base}/tareas?estado=hecha")->assertJsonCount(1, 'data')->assertJsonPath('data.0.evento_id', $ev->json('data.id'));
    $this->postJson("{$this->base}/eventos/{$ev->json('data.id')}/anulaciones", ['motivo' => 'Cargado en el animal equivocado'])->assertCreated();
    expect($neo->refresh()->estado)->toBe('pendiente');

    // Snapshot de la app trae las tareas abiertas.
    $snap = $this->getJson("/api/v1/sync/snapshot?establecimiento_id={$this->est->id}")->assertOk();
    expect(count($snap->json('data.tareas')))->toBe(5);
});

it('rabia: refuerzo 20–60 días tras la primera dosis; coprológico periódico hasta el destete', function () {
    DB::table('establecimientos')->where('id', $this->est->id)->update(['zonas_sanitarias' => json_encode(['rabia_endemica' => true])]);
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['aviso_aceptado' => true, 'plantillas' => ['rabia_paresiante', 'bub_coprologico_mensual']])->assertOk();
    expect(tareasDe($this->est->id, 'bub_coprologico_mensual'))->toHaveCount(0); // sin bucerros todavía

    $vaca = animalNuevo();
    $cria = animalNuevo(['categoria' => 'BUB_BUCERRA', 'fecha_nacimiento' => '2026-02-01']);
    $this->postJson("{$this->base}/eventos", altaBody([$vaca, $cria], '2026-03-01'))->assertCreated();
    $ids = [$vaca['animal_id'], $cria['animal_id']];
    sort($ids);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['producto_nombre' => 'Antirrábica', 'enfermedad' => 'rabia', 'numero_dosis' => 'primera'], 'ocurrido_en' => '2026-03-10'])->assertCreated();

    $ref = tareasDe($this->est->id, 'rabia_paresiante')->sole();
    expect([$ref->fecha_desde->toDateString(), $ref->fecha_hasta->toDateString()])->toBe(['2026-03-30', '2026-05-09'])
        ->and($ref->alcance['animal_ids'])->toBe($ids)->and($ref->cabezas_estimadas)->toBe(2);

    $copro = tareasDe($this->est->id, 'bub_coprologico_mensual');
    expect($copro->map(fn ($t) => $t->vence_en->toDateString())->all())->toBe(['2026-03-20', '2026-04-19', '2026-05-19', '2026-06-18'])
        ->and($copro->first()->cabezas_estimadas)->toBe(1);

    // Destete de la única cría → las tareas futuras del coprológico se omiten solas.
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$cria['animal_id']]],
        'payload' => ['tipo' => 'tradicional'], 'ocurrido_en' => '2026-03-20'])->assertCreated();
    $this->artisan('sanidad:generar-tareas')->assertSuccessful();
    expect(tareasDe($this->est->id, 'bub_coprologico_mensual')->pluck('estado')->unique()->all())->toBe(['omitida']);
});

it('tareas: manual, reprogramar (el generador respeta), omitir, filtros por estado y archivo del ítem', function () {
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['aviso_aceptado' => true, 'plantillas' => ['bub_pediculosis']])->assertOk();
    $t = tareasDe($this->est->id)->first();

    $this->patchJson("{$this->base}/tareas/{$t->id}", ['row_version' => 1, 'fecha_desde' => '2026-04-10', 'fecha_hasta' => '2026-04-20', 'vence_en' => '2026-04-20'])
        ->assertOk()->assertJsonPath('data.reprogramada', true)->assertJsonPath('data.row_version', 2);
    $this->patchJson("{$this->base}/tareas/{$t->id}", ['row_version' => 1, 'vence_en' => '2026-04-21'])->assertStatus(409);
    $this->artisan('sanidad:generar-tareas');
    expect($t->refresh()->vence_en->toDateString())->toBe('2026-04-20');

    $manual = $this->postJson("{$this->base}/tareas", ['titulo' => 'Revisar aguadas', 'tipo_evento' => 'sanidad.observacion_clinica', 'vence_en' => '2026-03-01'])
        ->assertCreated()->assertJsonPath('data.estado', 'vencida')->assertJsonPath('data.plan_item_id', null);
    $this->getJson("{$this->base}/tareas?estado=vencida")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/tareas?estado=pendiente")->assertJsonCount(2, 'data');
    $this->getJson("{$this->base}/tareas?desde=2026-04-01&hasta=2026-04-30")->assertJsonCount(1, 'data');
    $this->postJson("{$this->base}/tareas/{$manual->json('data.id')}/omitir", ['motivo' => 'No corresponde'])->assertOk()
        ->assertJsonPath('data.estado', 'omitida')->assertJsonPath('data.omitida_motivo', 'No corresponde');

    $item = DB::table('plan_items')->value('id');
    $this->postJson("{$this->base}/plan-sanitario/items/{$item}/archivar", ['motivo' => 'Lo hace el vecino'])->assertOk();
    expect(tareasDe($this->est->id, 'bub_pediculosis')->pluck('estado')->all())->toBe(['omitida', 'omitida']); // ítem archivado: también la reprogramada
    $this->getJson("{$this->base}/plan-sanitario")->assertJsonCount(0, 'data.items');
});

it('ítem manual, 422, 403 por rol y 404 ajeno', function () {
    $item = $this->postJson("{$this->base}/plan-sanitario/items", ['nombre' => 'Clostridiales', 'especie' => 'BOV', 'tipo_evento' => 'sanidad.vacunacion',
        'enfermedad' => 'clostridiales', 'disparador' => ['tipo' => 'fecha_fija', 'fecha' => '04-10'], 'alcance' => ['categorias' => ['BOV_TERNERO', 'BOV_TERNERA']]])
        ->assertCreated()->assertJsonPath('data.plantilla_codigo', null)->assertJsonPath('data.especie', 'BOV');
    expect(tareasDe($this->est->id)->sole()->vence_en->toDateString())->toBe('2026-04-10');

    $this->postJson("{$this->base}/plan-sanitario/items", ['nombre' => 'X', 'tipo_evento' => 'hacienda.pesaje', 'disparador' => ['tipo' => 'ventana', 'desde' => '13-01'],
        'enfermedad' => 'gripe', 'alcance' => ['categorias' => ['NADA']]])
        ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'tipo_evento', 'disparador.desde', 'enfermedad', 'alcance.categorias.0']);
    $this->postJson("{$this->base}/tareas", ['titulo' => 'X'])->assertStatus(422)->assertJsonValidationErrors(['tipo_evento', 'vence_en']);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/plan-sanitario")->assertOk()->assertJsonCount(1, 'data.items');
    $this->getJson("{$this->base}/tareas")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("{$this->base}/plan-sanitario/plantillas", ['plantillas' => ['aftosa_campana_1'], 'aviso_aceptado' => true])->assertForbidden();
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Algo', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-04-01'])->assertForbidden();

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/plan-sanitario")->assertNotFound();
    $this->postJson("{$this->base}/plan-sanitario/items", [])->assertNotFound();
    $this->patchJson("{$this->base}/plan-sanitario/items/{$item->json('data.id')}", ['row_version' => 1])->assertNotFound();
});
