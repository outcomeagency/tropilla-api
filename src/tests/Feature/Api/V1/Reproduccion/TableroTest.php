<?php

use App\Domain\Establecimientos\Models\Membresia;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));
    $this->hoy = Carbon::parse(now($this->est->zona_horaria)->toDateString());
    $d = fn (int $dias) => $this->hoy->copy()->addDays($dias)->toDateString();
    $this->d = $d;

    // Búfalas sin raza (310 d). Tacto hace 100 días con los días que dan la FPP buscada.
    $this->b = [];
    foreach (['proxima' => 3, 'mes_siguiente' => 40, 'vencida' => -20, 'atrasada' => -10] as $k => $fpp) {
        $this->b[$k] = animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => "B-{$k}"]]]);
    }
    foreach (['servida', 'vacia', 'parida', 'muerta'] as $k) {
        $this->b[$k] = animalNuevo();
    }
    $this->b['bubilla'] = animalNuevo(['categoria' => 'BUB_BUBILLA']);
    $otros = [animalNuevo(['categoria' => 'BUB_BUCERRA']), animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUFALO_TORO']),
        $this->vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA'])];
    $this->postJson("{$this->base}/eventos", altaBody([...array_values($this->b), ...$otros], $d(-400)))->assertCreated();

    $ev = fn (string $k, string $tipo, array $p, string $f) => $this->postJson("{$this->base}/eventos", ['tipo' => $tipo,
        'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->b[$k]['animal_id']]], 'payload' => (object) $p, 'ocurrido_en' => $f])->assertCreated();
    foreach (['proxima' => 3, 'mes_siguiente' => 40, 'vencida' => -20, 'atrasada' => -10, 'muerta' => 5] as $k => $fpp) {
        $ev($k, 'reproduccion.tacto', ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 310 - (100 + $fpp)], $d(-100));
    }
    $ev('servida', 'reproduccion.inseminacion', ['tipo' => 'ia'], $d(-30));
    $ev('vacia', 'reproduccion.tacto', ['metodo' => 'tacto', 'resultado' => 'vacia'], $d(-50));
    $ev('parida', 'reproduccion.parto', ['tipo' => 'normal', 'crias' => []], $d(-15));
    $ev('muerta', 'hacienda.muerte', ['causa' => 'accidente'], $d(-1));
});

it('tablero de vientres por especie: vacías, servidas, preñadas por mes de parto, paridas y alertas', function () {
    $res = $this->getJson("{$this->base}/reproduccion/tablero")->assertOk();
    $bub = collect($res->json('data'))->firstWhere('especie', 'BUB');
    $porMes = collect([3, 40, -20, -10])->map(fn ($n) => ($this->d)($n))->countBy(fn ($f) => substr($f, 0, 7))->sortKeys()
        ->map(fn ($n, $mes) => ['mes' => $mes, 'cabezas' => $n])->values()->all();

    expect($bub)->toMatchArray([
        'total_vientres' => 8, 'vacias' => 1, 'servidas' => 1, 'prenadas' => 4, 'paridas' => 1, 'sin_estado' => 1, // la bubilla; la muerta no cuenta
        'partos_proximos' => 1, 'partos_vencidos' => 1,
    ])->and($bub['prenadas_por_mes_parto'])->toBe($porMes);
    expect(collect($res->json('data'))->firstWhere('especie', 'BOV'))->toMatchArray(['total_vientres' => 1, 'sin_estado' => 1, 'prenadas' => 0]);

    $this->getJson("{$this->base}/reproduccion/tablero?especie=BOV")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.especie', 'BOV');
});

it('calendario de partos: ordenado por FPP, con días para el parto y vencido (+15 d sin registrar); filtros', function () {
    $res = $this->getJson("{$this->base}/reproduccion/partos")->assertOk();
    $ids = collect($res->json('data'))->pluck('animal.id')->all();
    expect($ids)->toBe(array_map(fn ($k) => $this->b[$k]['animal_id'], ['vencida', 'atrasada', 'proxima', 'mes_siguiente', 'servida']));
    expect($res->json('data.0'))->toMatchArray(['fecha_probable_parto' => ($this->d)(-20), 'fpp_precision' => 'tacto', 'dias_para_parto' => -20, 'vencido' => true])
        ->and($res->json('data.0.animal.identificacion_principal'))->toBe('B-vencida')
        ->and($res->json('data.1'))->toMatchArray(['dias_para_parto' => -10, 'vencido' => false])
        ->and($res->json('data.4'))->toMatchArray(['fpp_precision' => 'ia', 'dias_para_parto' => 280, 'vencido' => false]);

    $this->getJson("{$this->base}/reproduccion/partos?vencidos=true")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.animal.id', $this->b['vencida']['animal_id']);
    $this->getJson("{$this->base}/reproduccion/partos?vencidos=false")->assertOk()->assertJsonCount(4, 'data');
    $this->getJson("{$this->base}/reproduccion/partos?desde=".($this->d)(0).'&hasta='.($this->d)(60))->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.dias_para_parto', 3);
    $this->getJson("{$this->base}/reproduccion/partos?especie=BOV")->assertOk()->assertJsonCount(0, 'data');

    // Registrar el parto lo saca del calendario y de los vencidos.
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->b['vencida']['animal_id']]],
        'payload' => ['tipo' => 'normal', 'crias' => [['viva' => false, 'sexo' => 'H']]], 'ocurrido_en' => ($this->d)(-2)])->assertCreated();
    $this->getJson("{$this->base}/reproduccion/partos?vencidos=true")->assertOk()->assertJsonCount(0, 'data');
});

it('la tolerancia del vencido es parámetro del establecimiento', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->putJson("{$this->base}/parametros/parto_vencido_tolerancia_dias", ['valor' => 5, 'especie' => 'BUB', 'motivo' => 'Criterio del vet'])->assertOk();
    $this->getJson("{$this->base}/reproduccion/partos?vencidos=true")->assertOk()->assertJsonCount(2, 'data');
});

it('422 de filtros; lectura puede ver; revocada 403; otro establecimiento 404', function () {
    $this->getJson("{$this->base}/reproduccion/partos?desde=16/10/2026")->assertStatus(422)->assertJsonValidationErrors('desde');
    $this->getJson("{$this->base}/reproduccion/partos?desde=2026-10-10&hasta=2026-10-01")->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson("{$this->base}/reproduccion/tablero?especie=OVI")->assertStatus(422)->assertJsonValidationErrors('especie');
    $this->getJson("{$this->base}/reproduccion/partos?vencidos=quizas")->assertStatus(422);

    Sanctum::actingAs($lector = miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/reproduccion/tablero")->assertOk();
    Membresia::query()->where('usuario_id', $lector->id)->update(['estado' => 'revocada']);
    $this->getJson("{$this->base}/reproduccion/partos")->assertForbidden()->assertJsonPath('code', 'membresia_revocada');

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/reproduccion/tablero?especie=OVI")->assertNotFound();
    $this->getJson("{$this->base}/reproduccion/partos")->assertNotFound();
});
