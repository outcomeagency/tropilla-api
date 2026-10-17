<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function pedidoFlete(array $extra = []): array
{
    return array_replace([
        'destino_provincia_codigo' => '82', 'destino_localidad' => 'Reconquista', 'motivo' => 'venta_frigorifico',
        'fecha_desde' => Carbon::today()->addDays(3)->toDateString(), 'fecha_hasta' => Carbon::today()->addDays(10)->toDateString(),
        'especie_codigo' => 'BUB', 'categoria' => 'Novillos', 'cabezas' => 30, 'kg_promedio' => 420,
        'telefono_contacto' => '+54 3773 456789', 'consentimiento' => true,
    ], $extra);
}

/** Transportista con toda la documentación verificada (o con los ítems indicados pendientes). */
function transportistaVerificado($test, string $nombre = 'Transportes Ñandubay', array $sinVerificar = []): string
{
    $id = $test->postJson('/api/v1/fletes/concierge/transportistas', [
        'nombre' => $nombre, 'cuit' => '30-50001091-2', 'contacto_nombre' => 'Rubén Acosta', 'telefono' => '3773 15 445566',
        'base_departamento_codigo' => '18105', 'consentimiento' => true,
    ])->assertCreated()->json('data.id');
    foreach (['identidad_cuit', 'senasa_vehiculo', 'ruta', 'licencia_chofer', 'rto', 'seguro'] as $item) {
        if (! in_array($item, $sinVerificar, true)) {
            $test->putJson("/api/v1/fletes/concierge/transportistas/{$id}/documentos/{$item}", [
                'estado' => 'verificado', 'metodo' => 'documento_a_la_vista', 'vence_el' => Carbon::today()->addYear()->toDateString(),
            ])->assertOk();
        }
    }

    return $id;
}

function disponibilidad($test, string $transportista, array $extra = []): string
{
    $test->postJson("/api/v1/fletes/concierge/transportistas/{$transportista}/disponibilidades", array_replace([
        'origen_departamento_codigo' => '18035', 'destino_provincia_codigo' => '82', 'desde' => Carbon::today()->toDateString(),
        'hasta' => Carbon::today()->addDays(15)->toDateString(), 'jaula' => 'acoplado', 'capacidad_cabezas' => 40, 'es_retorno' => true, 'tarifa_km' => 1800,
    ], $extra))->assertCreated();

    return DB::table('fletes_disponibilidades')->where('transportista_id', $transportista)->orderByDesc('created_at')->value('id');
}

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}/fletes";
    $this->dueno = duenoDe($this->est);
    $this->staff = User::factory()->create(['es_staff' => true]);
});

it('el productor pide un flete con consentimiento; queda en el corredor y sin DT-e', function () {
    Sanctum::actingAs($this->dueno);
    $op = $this->getJson("{$this->base}/opciones")->assertOk()->json('data');
    expect($op['emite_dte'])->toBeFalse()
        ->and($op['en_corredor'])->toBeTrue()
        ->and($op['origen_sugerido']['departamento_nombre'])->toBe('Mercedes')
        ->and(collect($op['checklist'])->pluck('item'))->toContain('dte')
        ->and($op['avisos'][0])->toContain('no emite');

    $this->postJson("{$this->base}/pedidos", pedidoFlete(['consentimiento' => false]))->assertUnprocessable()->assertJsonValidationErrors('consentimiento');
    $this->postJson("{$this->base}/pedidos", pedidoFlete(['fecha_desde' => Carbon::today()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('fecha_desde');

    $p = $this->postJson("{$this->base}/pedidos", pedidoFlete())->assertCreated()->json('data');
    expect($p['estado'])->toBe('pendiente')
        ->and($p['en_corredor'])->toBeTrue()
        ->and($p['origen']['departamento_codigo'])->toBe('18105')
        ->and($p['destino']['provincia_nombre'])->toBe('Santa Fe')
        ->and($p['consentimiento']['version'])->not->toBeEmpty()
        ->and($p['checklist'])->toHaveCount(6)
        ->and($p['historial'][0]['accion'])->toBe('pedido_creado');

    $fuera = $this->postJson("{$this->base}/pedidos", pedidoFlete(['origen_departamento_codigo' => '22140']))->assertCreated()->json('data');
    expect($fuera['en_corredor'])->toBeFalse();

    $this->getJson("{$this->base}/pedidos?estado=abiertos")->assertOk()->assertJsonCount(2, 'data');
});

it('permisos: lectura ve pero no pide; la mesa del concierge es solo para staff', function () {
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/pedidos")->assertOk();
    $this->postJson("{$this->base}/pedidos", pedidoFlete())->assertForbidden();
    $this->getJson('/api/v1/fletes/concierge/pedidos')->assertForbidden();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("{$this->base}/pedidos")->assertNotFound();
});

it('checklist documental: no se propone un transportista sin documentación verificada y vigente', function () {
    Sanctum::actingAs($this->dueno);
    $pedido = $this->postJson("{$this->base}/pedidos", pedidoFlete())->json('data.id');

    Sanctum::actingAs($this->staff);
    $t = transportistaVerificado($this, sinVerificar: ['seguro']);
    $this->putJson("/api/v1/fletes/concierge/transportistas/{$t}/documentos/ruta", ['estado' => 'verificado'])
        ->assertUnprocessable()->assertJsonValidationErrors('metodo');
    $this->putJson("/api/v1/fletes/concierge/transportistas/{$t}/documentos/rto", ['estado' => 'verificado', 'metodo' => 'copia_digital', 'vence_el' => Carbon::yesterday()->toDateString()])
        ->assertUnprocessable();
    $this->putJson("/api/v1/fletes/concierge/transportistas/{$t}/documentos/identidad_cuit", ['estado' => 'no_aplica'])->assertUnprocessable();

    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $t, 'fecha_propuesta' => Carbon::today()->addDays(4)->toDateString()])
        ->assertUnprocessable()->assertJsonPath('code', 'regla_bloqueante');

    $this->putJson("/api/v1/fletes/concierge/transportistas/{$t}/documentos/seguro", ['estado' => 'verificado', 'metodo' => 'consulta_autoridad', 'vence_el' => Carbon::today()->addMonths(6)->toDateString()])
        ->assertOk()->assertJsonPath('data.documentacion_completa', true);
    $det = $this->getJson("/api/v1/fletes/concierge/transportistas/{$t}")->assertOk()->json('data');
    expect(collect($det['documentos'])->firstWhere('item', 'seguro')['verificado_por'])->toBe($this->staff->nombre)
        ->and(collect($det['historial'])->pluck('accion'))->toContain('documento_verificado');

    // Un documento vencido después de verificado vuelve a bloquear.
    DB::table('fletes_transportista_documentos')->where('transportista_id', $t)->where('item', 'rto')->update(['vence_el' => Carbon::yesterday()->toDateString()]);
    $this->getJson("/api/v1/fletes/concierge/transportistas/{$t}")->assertJsonPath('data.documentacion_completa', false);
});

it('matching por ruta, capacidad y fecha con criterios explicados', function () {
    Sanctum::actingAs($this->dueno);
    $pedido = $this->postJson("{$this->base}/pedidos", pedidoFlete())->json('data.id');

    Sanctum::actingAs($this->staff);
    $cerca = transportistaVerificado($this, 'Cerca Retorno');
    $dCerca = disponibilidad($this, $cerca); // Curuzú Cuatiá → Santa Fe, retorno, 40 cab.
    $chico = transportistaVerificado($this, 'Chico Sin Papeles', ['seguro']);
    disponibilidad($this, $chico, ['origen_departamento_codigo' => '18105', 'destino_provincia_codigo' => null, 'capacidad_cabezas' => 18, 'es_retorno' => false, 'jaula' => 'chasis']);
    $lejos = transportistaVerificado($this, 'Lejos');
    disponibilidad($this, $lejos, ['origen_departamento_codigo' => '18168']); // Santo Tomé: > 150 km
    $otroDestino = transportistaVerificado($this, 'Otro destino');
    disponibilidad($this, $otroDestino, ['destino_provincia_codigo' => '06']);
    $sinFecha = transportistaVerificado($this, 'Sin fecha');
    disponibilidad($this, $sinFecha, ['desde' => Carbon::today()->addDays(20)->toDateString(), 'hasta' => Carbon::today()->addDays(25)->toDateString()]);
    $soloBov = transportistaVerificado($this, 'Solo bovinos');
    disponibilidad($this, $soloBov, ['especies' => ['BOV']]);

    $d = $this->getJson("/api/v1/fletes/concierge/pedidos/{$pedido}")->assertOk()->json('data');
    $sug = collect($d['sugerencias']);
    expect($sug->pluck('transportista_nombre')->all())->toBe(['Cerca Retorno', 'Chico Sin Papeles'])
        ->and($sug[0]['disponibilidad_id'])->toBe($dCerca)
        ->and($sug[0]['proponible'])->toBeTrue()
        ->and($sug[0]['distancia_salida_km'])->toBeGreaterThan(50)->toBeLessThan(90)
        ->and(collect($sug[0]['criterios'])->firstWhere('criterio', 'retorno')['cumple'])->toBeTrue()
        ->and($sug[1]['proponible'])->toBeFalse()
        ->and(collect($sug[1]['criterios'])->firstWhere('criterio', 'capacidad')['detalle'])->toContain('2 viajes')
        ->and($d['contacto']['telefono'])->toBe('+54 3773 456789');
});

it('ciclo completo: propuesta, aceptación con contacto, rechazo, resultado y métricas', function () {
    Sanctum::actingAs($this->dueno);
    $pedido = $this->postJson("{$this->base}/pedidos", pedidoFlete())->json('data.id');

    Sanctum::actingAs($this->staff);
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/tomar")->assertOk()->assertJsonPath('data.estado', 'en_gestion');
    $a = transportistaVerificado($this, 'Transportes A');
    $dA = disponibilidad($this, $a);
    $b = transportistaVerificado($this, 'Transportes B');
    $fecha = Carbon::today()->addDays(5)->toDateString();
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $a, 'disponibilidad_id' => $dA, 'fecha_propuesta' => $fecha, 'tarifa_estimada' => 950000])
        ->assertCreated()->assertJsonPath('data.estado', 'propuesto');
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $a, 'fecha_propuesta' => $fecha])->assertUnprocessable();
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $b, 'fecha_propuesta' => Carbon::today()->addDays(30)->toDateString()])->assertUnprocessable();
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $b, 'fecha_propuesta' => $fecha, 'nota' => 'Coordinado por teléfono'])->assertCreated();

    expect(DB::table('notificaciones')->where('usuario_id', $this->dueno->id)->where('tipo', 'flete_propuesta')->count())->toBe(2);

    Sanctum::actingAs($this->dueno);
    $p = $this->getJson("{$this->base}/pedidos/{$pedido}")->json('data');
    expect($p['propuestas'])->toHaveCount(2)
        ->and($p['propuestas'][0]['transportista']['contacto'])->toBeNull()
        ->and($p['propuestas'][0]['transportista']['documentacion_completa'])->toBeTrue()
        ->and($p['propuestas'][0]['compatibilidad']['criterios'])->not->toBeEmpty()
        ->and(collect($p['historial'])->pluck('quien'))->toContain('Equipo TROPILLA');
    [$propA, $propB] = [$p['propuestas'][0]['id'], $p['propuestas'][1]['id']];

    $this->postJson("{$this->base}/pedidos/{$pedido}/resultado", ['resultado' => 'acordado'])->assertUnprocessable();
    $this->postJson("{$this->base}/pedidos/{$pedido}/propuestas/{$propB}/rechazar", ['motivo' => 'fecha'])->assertOk();
    $p = $this->postJson("{$this->base}/pedidos/{$pedido}/propuestas/{$propA}/aceptar")->assertOk()->json('data');
    expect($p['estado'])->toBe('contactado')
        ->and($p['propuestas'][0]['transportista']['contacto']['telefono'])->toBe('3773 15 445566')
        ->and($p['propuestas'][1]['estado'])->toBe('rechazada')
        ->and(collect($p['historial'])->pluck('accion'))->toContain('contacto_compartido');

    $this->putJson("{$this->base}/pedidos/{$pedido}/checklist/dte", ['estado' => 'hecho', 'nota' => 'Lo saca la consignataria'])->assertOk()
        ->assertJsonPath('data.checklist.0.estado', 'hecho');
    $p = $this->postJson("{$this->base}/pedidos/{$pedido}/resultado", ['resultado' => 'acordado', 'costo' => 900000, 'km' => 500, 'nota' => 'Viajó el martes'])
        ->assertOk()->json('data');
    expect($p['estado'])->toBe('acordado')->and($p['resultado']['costo_por_km'])->toBe('1800');
    $this->postJson("{$this->base}/pedidos/{$pedido}/cancelar")->assertUnprocessable();

    // Otro pedido: sin acuerdo.
    $otro = $this->postJson("{$this->base}/pedidos", pedidoFlete())->json('data.id');
    $this->postJson("{$this->base}/pedidos/{$otro}/resultado", ['resultado' => 'sin_acuerdo', 'nota' => 'No hubo camión'])->assertOk();

    Sanctum::actingAs($this->staff);
    $m = $this->getJson('/api/v1/fletes/concierge/resumen')->assertOk()->json('data.metricas');
    expect($m['pedidos_total'])->toBe(2)
        ->and($m['pedidos_por_estado']['acordado'])->toBe(1)
        ->and($m['tasa_acuerdo_pct'])->toBe(50)
        ->and($m['costo_por_km'])->toBe('1800')
        ->and($m['cabezas_acordadas'])->toBe(30)
        ->and($m['transportistas_documentados'])->toBe(2)
        ->and($m['horas_hasta_primera_propuesta'])->not->toBeNull()
        ->and($m['rechazos_por_motivo'][0]['motivo'])->toBe('Fecha u horario');

    expect(fn () => DB::table('fletes_bitacora')->limit(1)->delete())->toThrow(Exception::class);
});

it('cancelar retira propuestas pendientes; retirar la última vuelve el pedido a gestión', function () {
    Sanctum::actingAs($this->dueno);
    $pedido = $this->postJson("{$this->base}/pedidos", pedidoFlete())->json('data.id');
    Sanctum::actingAs($this->staff);
    $t = transportistaVerificado($this);
    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $t, 'fecha_propuesta' => Carbon::today()->addDays(4)->toDateString()])->assertCreated();
    $prop = DB::table('fletes_propuestas')->where('pedido_id', $pedido)->value('id');
    $this->postJson("/api/v1/fletes/concierge/propuestas/{$prop}/retirar", ['motivo' => 'Se rompió el camión'])->assertOk()->assertJsonPath('data.estado', 'en_gestion');

    $this->postJson("/api/v1/fletes/concierge/pedidos/{$pedido}/propuestas", ['transportista_id' => $t, 'fecha_propuesta' => Carbon::today()->addDays(4)->toDateString()])->assertCreated();
    Sanctum::actingAs($this->dueno);
    $p = $this->postJson("{$this->base}/pedidos/{$pedido}/cancelar", ['motivo' => 'Se postergó la venta'])->assertOk()->json('data');
    expect($p['estado'])->toBe('cancelado')->and(collect($p['propuestas'])->pluck('estado')->all())->toBe(['retirada', 'retirada']);
});
