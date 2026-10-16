<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/eventos";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));

    $this->hembra = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->toro = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUFALO_TORO', 'raza' => 'BUB_MURRAH']);
    $this->vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_BRAFORD']);
    $this->postJson($this->url, altaBody([$this->hembra, $this->toro, $this->vaca], '2025-01-01'))->assertCreated();
});

function repEvento(string $tipo, array $animalIds, array $payload, string $fecha, array $extra = []): array
{
    sort($animalIds);

    return ['tipo' => $tipo, 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $animalIds], 'payload' => $payload, 'ocurrido_en' => $fecha, ...$extra];
}

function estRep(string $id): object
{
    return DB::table('animales_estado')->where('animal_id', $id)->first();
}

it('servicio natural: toros y período → hembra «servida» con FPP desde el inicio; el toro del lote no cambia', function () {
    $ids = [$this->hembra['animal_id'], $this->toro['animal_id']]; // lote de servicio expandido con el toro adentro (M6)
    $this->postJson($this->url, repEvento('reproduccion.servicio_natural', $ids,
        ['inicio' => '2025-03-01', 'fin' => '2025-05-30', 'toro_ids' => [$this->toro['animal_id']], 'toros_externos' => ['Toro prestado del vecino']], '2025-03-01'))
        ->assertCreated()->assertJsonPath('data.tipo', 'reproduccion.servicio_natural');

    expect(estRep($this->hembra['animal_id']))->estado_reproductivo->toBe('servida')->servicio_tipo->toBe('natural')
        ->servicio_fecha->toBe('2025-03-01')->fecha_probable_parto->toBe('2025-12-29')->fpp_precision->toBe('servicio_rango'); // + 303 d Murrah
    expect(estRep($this->toro['animal_id']))->estado_reproductivo->toBeNull();

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$this->hembra['animal_id']}")->assertOk()
        ->assertJsonPath('data.estado_reproductivo', 'servida')->assertJsonPath('data.servicio_tipo', 'natural')
        ->assertJsonPath('data.fpp_precision', 'servicio_rango');
});

it('IA/IATF: semen (toro, raza, centro) e inseminador → «servida» con FPP por IA', function () {
    $this->postJson($this->url, repEvento('reproduccion.inseminacion', [$this->vaca['animal_id']], ['tipo' => 'iatf',
        'semen' => ['toro_nombre' => 'Brangus Don Pepe', 'raza' => 'BOV_BRANGUS', 'centro' => 'CIAVT', 'partida' => 'L-2231'],
        'inseminador' => 'Juan Pérez'], '2025-04-10'))->assertCreated();
    expect(estRep($this->vaca['animal_id']))->estado_reproductivo->toBe('servida')->servicio_tipo->toBe('iatf')
        ->fecha_probable_parto->toBe('2026-01-18')->fpp_precision->toBe('ia'); // + 283 d bovino

    // Semen de un toro propio.
    $this->postJson($this->url, repEvento('reproduccion.inseminacion', [$this->hembra['animal_id']], ['tipo' => 'ia',
        'semen' => ['toro_id' => $this->toro['animal_id'], 'raza' => 'BUB_MURRAH']], '2025-04-10'))->assertCreated();
    expect(estRep($this->hembra['animal_id']))->servicio_tipo->toBe('ia');
});

it('reglas: período invertido, sin hembras, toro hembra/castrado/de otra especie/inexistente, raza del semen', function () {
    $h = [$this->hembra['animal_id']];
    $sn = fn (array $p, ?array $ids = null) => repEvento('reproduccion.servicio_natural', $ids ?? $h, ['inicio' => '2025-03-01', ...$p], '2025-03-01');

    $this->postJson($this->url, $sn(['fin' => '2025-02-01']))->assertStatus(422)->assertJsonPath('code', 'validacion')
        ->assertJsonValidationErrors('payload.fin');
    $this->postJson($this->url, $sn([], [$this->toro['animal_id']]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, $sn(['toro_ids' => [$this->vaca['animal_id']]]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, $sn(['toro_ids' => [(string) Str::uuid7()]]))->assertStatus(422)->assertJsonPath('code', 'validacion');

    $bovToro = animalNuevo(['especie' => 'BOV', 'sexo' => 'M', 'categoria' => 'BOV_TORO']);
    $buey = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUEY', 'castrado' => true]);
    $this->postJson($this->url, altaBody([$bovToro, $buey], '2025-01-01'))->assertCreated();
    $this->postJson($this->url, $sn(['toro_ids' => [$bovToro['animal_id']]]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, $sn(['toro_ids' => [$buey['animal_id']]]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $lote = crearLote($this->est, 'BOV', false, 'Toros');
    $this->postJson($this->url, $sn(['lote_toros_id' => $lote]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $this->postJson($this->url, repEvento('reproduccion.inseminacion', $h, ['tipo' => 'ia', 'semen' => ['raza' => 'BOV_ANGUS']], '2025-03-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, repEvento('reproduccion.inseminacion', $h, ['tipo' => 'ia', 'semen' => ['toro_id' => $bovToro['animal_id']]], '2025-03-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    // IA en un macho: sexo requerido por tipo (dato).
    $this->postJson($this->url, repEvento('reproduccion.inseminacion', [$this->toro['animal_id']], ['tipo' => 'ia'], '2025-03-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    // Schema: tipo obligatorio.
    $this->postJson($this->url, repEvento('reproduccion.inseminacion', $h, [], '2025-03-01'))->assertStatus(422)->assertJsonPath('code', 'validacion');
    expect(estRep($this->hembra['animal_id']))->estado_reproductivo->toBeNull();
});

it('servicio sobre una hembra preñada pide confirmación en la web', function () {
    $h = [$this->hembra['animal_id']];
    $this->postJson($this->url, repEvento('reproduccion.tacto', $h, ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 90], '2025-06-01'))->assertCreated();

    $body = repEvento('reproduccion.inseminacion', $h, ['tipo' => 'ia'], '2025-06-10');
    $this->postJson($this->url, $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonPath('advertencias.0.codigo', 'servicio_en_prenada');
    $this->postJson($this->url, [...$body, 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.observaciones_validacion', ['servicio_en_prenada']);
    expect(estRep($this->hembra['animal_id']))->estado_reproductivo->toBe('servida');
});

it('permisos: lectura 403, otro establecimiento 404', function () {
    $body = repEvento('reproduccion.inseminacion', [$this->hembra['animal_id']], ['tipo' => 'ia'], '2025-03-01');
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson($this->url, $body)->assertForbidden();

    $otro = crearEstablecimiento();
    Sanctum::actingAs(miembro($otro, 'encargado'));
    $this->postJson($this->url, $body)->assertNotFound();
    $this->postJson("/api/v1/establecimientos/{$otro->id}/eventos", $body)->assertStatus(422); // el animal no es de ese campo
});
