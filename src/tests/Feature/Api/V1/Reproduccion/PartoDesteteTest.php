<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));
    $this->madre = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$this->madre], '2024-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.inseminacion', [$this->madre['animal_id']], ['tipo' => 'ia'], '2025-03-01'))->assertCreated();
});

function eventoRep(string $tipo, array $animalIds, array $payload, string $fecha): array
{
    sort($animalIds);

    return ['tipo' => $tipo, 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $animalIds], 'payload' => (object) $payload, 'ocurrido_en' => $fecha];
}

function estadoAnimal(string $id): object
{
    return DB::table('animales_estado')->where('animal_id', $id)->first();
}

it('parto: crea las crías vivas enlazadas a la madre (mellizos + una muerta) y la madre queda parida', function () {
    $macho = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'raza' => 'BUB_MURRAH']);
    $hembra = animalNuevo(['categoria' => 'BUB_BUCERRA', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'B25-001']]]);
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.parto', [$this->madre['animal_id']], ['tipo' => 'asistido', 'crias' => [
        ['viva' => true, 'animal' => $macho, 'peso_kg' => '38'],
        ['viva' => true, 'animal' => [...$hembra, 'madre_id' => $this->madre['animal_id']], 'peso_kg' => '35.5'],
        ['viva' => false, 'sexo' => 'M'],
    ]], '2025-12-27'))->assertCreated();

    expect(estadoAnimal($this->madre['animal_id']))->partos->toBe(1)->estado_reproductivo->toBe('parida')
        ->ultimo_parto_fecha->toBe('2025-12-27')->fecha_probable_parto->toBeNull();
    foreach ([[$macho, '38.0'], [$hembra, '35.5']] as [$c, $peso]) {
        expect(estadoAnimal($c['animal_id']))->madre_id->toBe($this->madre['animal_id'])->origen->toBe('nacido')
            ->fecha_nacimiento->toBe('2025-12-27')->peso_kg->toBe($peso)->destetado->toBeFalse();
    }
    expect(DB::table('existencias')->where('establecimiento_id', $this->est->id)->whereIn('categoria_codigo', ['BUB_BUCERRO', 'BUB_BUCERRA'])->sum('cabezas'))->toBe(2);

    $this->getJson("{$this->base}/animales/{$this->madre['animal_id']}/genealogia")->assertOk()->assertJsonCount(2, 'data.crias');
    $this->getJson("{$this->base}/animales/{$this->madre['animal_id']}")->assertOk()
        ->assertJsonPath('data.partos', 1)->assertJsonPath('data.dias_posparto', (int) now()->setDate(2025, 12, 27)->startOfDay()->diffInDays(today(), false));
});

it('parto: hasta 3 crías, cría con categoría de cría y madre = sujeto; en macho bloquea', function () {
    $m = $this->madre['animal_id'];
    $cria = fn (array $x = []) => ['viva' => true, 'animal' => animalNuevo(['categoria' => 'BUB_BUCERRA', ...$x])];
    $parto = fn (array $crias) => eventoRep('reproduccion.parto', [$m], ['tipo' => 'normal', 'crias' => $crias], '2025-12-27');

    $this->postJson("{$this->base}/eventos", $parto([$cria(), $cria(), $cria(), $cria()]))->assertStatus(422)->assertJsonPath('code', 'validacion');
    $this->postJson("{$this->base}/eventos", $parto([$cria(['categoria' => 'BUB_BUBILLA'])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $otra = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$otra], '2024-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", $parto([$cria(['madre_id' => $otra['animal_id']])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", $parto([['viva' => true]]))->assertStatus(422)->assertJsonPath('code', 'validacion'); // viva sin animal
    $this->postJson("{$this->base}/eventos", $parto([$cria(['especie' => 'BOV', 'categoria' => 'BOV_TERNERA'])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    expect(estadoAnimal($m))->partos->toBe(0);
});

it('gestación mínima por especie: parto antes de mínimo − 30 días desde el servicio o desde el parto anterior pide confirmación', function () {
    $m = $this->madre['animal_id'];
    // IA 01/03/2025 → 01/08/2025 son 153 días (búfala: mínimo 295 − 30 = 265).
    $body = eventoRep('reproduccion.parto', [$m], ['tipo' => 'normal', 'crias' => [['viva' => false, 'sexo' => 'H']]], '2025-08-01');
    $this->postJson("{$this->base}/eventos", $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonPath('advertencias.0.codigo', 'gestacion_corta')->assertJsonPath('advertencias.0.detalle.minimo', 265);
    $this->postJson("{$this->base}/eventos", [...$body, 'confirmar_advertencias' => true])->assertCreated();

    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.parto', [$m], ['tipo' => 'normal', 'crias' => []], '2025-10-01'))
        ->assertStatus(422)->assertJsonPath('advertencias.0.codigo', 'parto_reciente');

    // Bovino: mínimo 270 − 30 = 240; con 250 días no advierte.
    $vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $this->postJson("{$this->base}/eventos", altaBody([$vaca], '2024-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.tacto', [$vaca['animal_id']], ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 60], '2025-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.parto', [$vaca['animal_id']], ['tipo' => 'normal', 'crias' => []], '2025-07-10'))->assertCreated(); // 250 d
});

it('aborto vuelve la hembra a vacía; sin preñez advierte; más días que la máxima bloquea', function () {
    $m = $this->madre['animal_id'];
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.tacto', [$m], ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 60], '2025-05-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.aborto', [$m], ['dias_gestacion' => 400], '2025-06-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.aborto', [$m], ['dias_gestacion' => 90, 'causa_presunta' => 'Brucelosis (sospecha)'], '2025-06-01'))->assertCreated();
    expect(estadoAnimal($m))->estado_reproductivo->toBe('vacia')->fecha_probable_parto->toBeNull()->partos->toBe(0);

    $body = eventoRep('reproduccion.aborto', [$m], [], '2025-06-10');
    $this->postJson("{$this->base}/eventos", $body)->assertStatus(422)->assertJsonPath('advertencias.0.codigo', 'aborto_sin_prenez');
});

it('destete con peso: con categoría nueva recategoriza la cría; sin ella queda la sugerencia; categoría de cría bloquea', function () {
    $h = animalNuevo(['categoria' => 'BUB_BUCERRA', 'fecha_nacimiento' => '2025-12-27']);
    $mc = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'fecha_nacimiento' => '2025-12-27']);
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.parto', [$this->madre['animal_id']], ['tipo' => 'normal',
        'crias' => [['viva' => true, 'animal' => $h], ['viva' => true, 'animal' => $mc]]], '2025-12-27'))->assertCreated();

    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.destete', [$h['animal_id']], ['tipo' => 'tradicional', 'peso_kg' => '160', 'categoria_nueva' => 'BUB_BUCERRA'], '2026-08-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.destete', [$h['animal_id']], ['tipo' => 'tradicional', 'peso_kg' => '160', 'categoria_nueva' => 'BUB_BUBILLA'], '2026-08-01'))->assertCreated();
    expect(estadoAnimal($h['animal_id']))->categoria_codigo->toBe('BUB_BUBILLA')->destetado->toBeTrue()
        ->destete_fecha->toBe('2026-08-01')->destete_peso_kg->toBe('160.0')->categoria_sugerida_codigo->toBeNull();
    $this->getJson("{$this->base}/animales/{$h['animal_id']}")->assertOk()
        ->assertJsonPath('data.destete', ['fecha' => '2026-08-01', 'peso_kg' => '160'])->assertJsonPath('data.categoria', 'BUB_BUBILLA');

    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.destete', [$mc['animal_id']], ['tipo' => 'precoz', 'peso_kg' => '150'], '2026-08-01'))->assertCreated();
    expect(estadoAnimal($mc['animal_id']))->categoria_codigo->toBe('BUB_BUCERRO')->destetado->toBeTrue()->categoria_sugerida_codigo->toBe('BUB_BUTORITO');

    $this->postJson("{$this->base}/eventos", eventoRep('reproduccion.destete', [$mc['animal_id']], ['tipo' => 'tradicional'], '2026-08-02'))
        ->assertStatus(422)->assertJsonPath('advertencias.0.codigo', 'ya_destetado');
});

it('destete de lote anónimo: el conteo pasa a la categoría nueva (un destete por sexo)', function () {
    $lote = crearLote($this->est);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRA' => 10, 'BUB_BUCERRO' => 8]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-10'])->assertCreated();
    $destete = fn (array $conteo, string $nueva) => ['tipo' => 'reproduccion.destete', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => $conteo],
        'payload' => ['tipo' => 'tradicional', 'peso_kg' => '155', 'categoria_nueva' => $nueva], 'ocurrido_en' => '2026-08-01'];

    $this->postJson("{$this->base}/eventos", $destete(['BUB_BUCERRA' => 10, 'BUB_BUCERRO' => 8], 'BUB_BUBILLA'))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", $destete(['BUB_BUCERRA' => 10], 'BUB_BUBILLA'))->assertCreated();
    expect(DB::table('lote_conteos')->where('lote_id', $lote)->pluck('cabezas', 'categoria_codigo')->all())->toEqual(['BUB_BUBILLA' => 10, 'BUB_BUCERRO' => 8])
        ->and((int) DB::table('existencias')->where('establecimiento_id', $this->est->id)->where('categoria_codigo', 'BUB_BUBILLA')->value('cabezas'))->toBe(10);
});

it('permisos: lectura 403, otro establecimiento 404', function () {
    $body = eventoRep('reproduccion.aborto', [$this->madre['animal_id']], [], '2025-06-01');
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/eventos", $body)->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("{$this->base}/eventos", $body)->assertNotFound();
});
