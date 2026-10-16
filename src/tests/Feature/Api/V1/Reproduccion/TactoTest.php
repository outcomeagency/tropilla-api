<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));
});

function tacto(string $animalId, array $payload, string $fecha): array
{
    return ['tipo' => 'reproduccion.tacto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$animalId]],
        'payload' => ['metodo' => 'tacto', ...$payload], 'ocurrido_en' => $fecha];
}

function fppDe(string $animalId): ?string
{
    return DB::table('animales_estado')->where('animal_id', $animalId)->value('fecha_probable_parto');
}

it('preñada con días: FPP con la gestación por especie/raza (Murrah 303, Mediterránea 313, Jafarabadi 330, búfalo 310, bovino 283)', function () {
    $hembras = [
        'BUB_MURRAH' => [animalNuevo(['raza' => 'BUB_MURRAH']), '2027-03-23'],
        'BUB_MEDITERRANEA' => [animalNuevo(['raza' => 'BUB_MEDITERRANEA']), '2027-04-02'],
        'BUB_JAFARABADI' => [animalNuevo(['raza' => 'BUB_JAFARABADI']), '2027-04-19'],
        'sin_raza' => [animalNuevo(), '2027-03-30'],
        'BOV' => [animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_BRAFORD']), '2027-03-03'],
    ];
    $this->postJson("{$this->base}/eventos", altaBody(array_column($hembras, 0), '2026-01-01'))->assertCreated();

    foreach ($hembras as [$h, $fpp]) {
        $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'prenada', 'dias_gestacion' => 100, 'veterinario' => 'Vet. Pérez'], '2026-09-01'))
            ->assertCreated();
        expect(fppDe($h['animal_id']))->toBe($fpp);
    }

    $murrah = $hembras['BUB_MURRAH'][0]['animal_id'];
    $diasHoy = 100 + (int) now()->setDate(2026, 9, 1)->startOfDay()->diffInDays(today(), false);
    $this->getJson("{$this->base}/animales/{$murrah}")->assertOk()
        ->assertJsonPath('data.estado_reproductivo', 'prenada')->assertJsonPath('data.fecha_probable_parto', '2027-03-23')
        ->assertJsonPath('data.fpp_precision', 'tacto')->assertJsonPath('data.tacto_fecha', '2026-09-01')
        ->assertJsonPath('data.dias_gestacion', $diasHoy);
    $this->getJson("{$this->base}/animales/{$murrah}/eventos?modulo=reproduccion")->assertOk()
        ->assertJsonPath('data.0.resumen', 'Preñada (100 días)');

    // Vacía: limpia FPP y días; el resumen lleva tilde.
    $this->postJson("{$this->base}/eventos", tacto($murrah, ['resultado' => 'vacia'], '2026-10-01'))->assertCreated();
    $this->getJson("{$this->base}/animales/{$murrah}/eventos?modulo=reproduccion")->assertJsonPath('data.0.resumen', 'Vacía');
    expect(DB::table('animales_estado')->where('animal_id', $murrah)->first())
        ->estado_reproductivo->toBe('vacia')->fecha_probable_parto->toBeNull()->fpp_precision->toBeNull();
});

it('la gestación es dato: el parámetro del establecimiento cambia la FPP de los tactos siguientes', function () {
    $h = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$h], '2026-01-01'))->assertCreated();
    Sanctum::actingAs(duenoDe($this->est));
    $this->putJson("{$this->base}/parametros/gestacion_media_dias", ['valor' => 300, 'especie' => 'BUB', 'motivo' => 'Dato del vet'])->assertOk();

    $hoy = today()->toDateString();
    $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'prenada', 'dias_gestacion' => 100], $hoy))->assertCreated();
    expect(fppDe($h['animal_id']))->toBe(today()->addDays(200)->toDateString());
});

it('tacto sin días: preñada exige días o tamaño; solo tamaño conserva la FPP del servicio', function () {
    $h = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$h], '2026-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.inseminacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$h['animal_id']]],
        'payload' => ['tipo' => 'ia'], 'ocurrido_en' => '2026-03-01'])->assertCreated();

    $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'prenada'], '2026-05-01'))
        ->assertStatus(422)->assertJsonPath('code', 'validacion')->assertJsonValidationErrors('payload.dias_gestacion');
    $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'prenada', 'tamano' => 'chica', 'metodo' => 'ecografia'], '2026-05-01'))->assertCreated();
    $this->getJson("{$this->base}/animales/{$h['animal_id']}/eventos?modulo=reproduccion")->assertJsonPath('data.0.resumen', 'Preñada (chica)');
    expect(DB::table('animales_estado')->where('animal_id', $h['animal_id'])->first())
        ->estado_reproductivo->toBe('prenada')->fecha_probable_parto->toBe('2026-12-29')->fpp_precision->toBe('ia');
});

it('días de gestación: más que la máxima de la especie bloquea; más que la media advierte (FPP pasada)', function () {
    $bov = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $this->postJson("{$this->base}/eventos", altaBody([$bov], '2026-01-01'))->assertCreated();

    $this->postJson("{$this->base}/eventos", tacto($bov['animal_id'], ['resultado' => 'prenada', 'dias_gestacion' => 300], '2026-09-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante'); // bovino: máx. 295
    $body = tacto($bov['animal_id'], ['resultado' => 'prenada', 'dias_gestacion' => 290], '2026-09-01');
    $this->postJson("{$this->base}/eventos", $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonPath('advertencias.0.codigo', 'fpp_pasada');
    $this->postJson("{$this->base}/eventos", [...$body, 'confirmar_advertencias' => true])->assertCreated();
    expect(fppDe($bov['animal_id']))->toBe('2026-08-25');

    // En búfalas 300 días es normal (máx. 335).
    $bub = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$bub], '2026-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", tacto($bub['animal_id'], ['resultado' => 'prenada', 'dias_gestacion' => 300], '2026-09-01'))->assertCreated();
});

it('tacto en macho bloquea; lectura 403; otro establecimiento 404', function () {
    $toro = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUFALO_TORO']);
    $h = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$toro, $h], '2026-01-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", tacto($toro['animal_id'], ['resultado' => 'vacia'], '2026-09-01'))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'vacia'], '2026-09-01'))->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("{$this->base}/eventos", tacto($h['animal_id'], ['resultado' => 'vacia'], '2026-09-01'))->assertNotFound();
});
