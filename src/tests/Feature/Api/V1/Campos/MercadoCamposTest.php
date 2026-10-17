<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** Aviso válido de pastaje en Corrientes (Mercedes). */
function avisoCampo(array $extra = []): array
{
    return array_replace([
        'titulo' => 'Pastaje en bajos de Mercedes', 'modalidad' => 'pastaje', 'especies' => ['BUB', 'BOV'],
        'capacidad_cabezas' => 120, 'superficie_ha' => '450', 'temporada_desde' => now()->toDateString(), 'temporada_hasta' => now()->addMonths(6)->toDateString(),
        'precio_unidad' => 'por_cabeza_mes', 'precio_valor' => '9500', 'agua' => ['tajamar', 'rio_arroyo'], 'alambrado' => 'bueno',
        'instalaciones' => ['manga', 'corrales'], 'localidad' => 'Paraje Itá Pucú', 'mostrar_localidad' => false,
    ], $extra);
}

beforeEach(function () {
    // Anunciante: La Esperanza (Corrientes). Interesado: El Ceibo (Chaco) con 80 bubillos.
    $this->est = crearEstablecimiento();
    $this->dueno = duenoDe($this->est);
    $this->base = "/api/v1/establecimientos/{$this->est->id}/campos";
    $this->otro = crearEstablecimiento(null, ['nombre' => 'El Ceibo', 'provincia_codigo' => '22', 'departamento_codigo' => '22140', 'localidad' => null]);
    $this->interesado = duenoDe($this->otro);
    $lote = crearLote($this->otro, 'BUB', true, 'Bubillos');
    Sanctum::actingAs($this->interesado);
    $this->postJson("/api/v1/establecimientos/{$this->otro->id}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUBILLO' => 80]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    Sanctum::actingAs($this->dueno);
});

it('publicar → buscar compatible con el rodeo → consultar con consentimiento → responder comparte contacto → acuerdo', function () {
    $a = $this->postJson("{$this->base}/avisos", avisoCampo())->assertCreated()->assertJsonPath('data.estado', 'borrador')
        ->assertJsonPath('data.verificacion.nivel', 'declarado')->assertJsonPath('data.zona.departamento_codigo', '18105')->json('data');
    // Borrador: no aparece en el mercado.
    Sanctum::actingAs($this->interesado);
    $this->getJson('/api/v1/campos')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/campos/{$a['id']}")->assertNotFound();

    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertOk()->assertJsonPath('data.estado', 'publicado');
    // Otro aviso que no calza: solo bovinos y capacidad chica.
    $b = $this->postJson("{$this->base}/avisos", avisoCampo(['titulo' => 'Arriendo chico para vacas', 'modalidad' => 'arrendamiento', 'especies' => ['BOV'], 'capacidad_cabezas' => 20,
        'precio_unidad' => 'a_convenir', 'precio_valor' => null]))->assertCreated()->json('data');
    $this->postJson("{$this->base}/avisos/{$b['id']}/publicar")->assertOk();

    Sanctum::actingAs($this->interesado);
    $r = $this->getJson("/api/v1/campos?establecimiento_id={$this->otro->id}")->assertOk()->json();
    expect($r['meta']['total'])->toBe(2)->and($r['meta']['orden'])->toBe('compatibilidad')
        ->and($r['meta']['rodeo'])->toBe([['especie' => 'BUB', 'cabezas' => 80]]);
    $primero = $r['data'][0];
    expect($primero['id'])->toBe($a['id'])
        ->and($primero['compatibilidad'])->toMatchArray(['puntaje' => 85, 'compatible' => true, 'especie' => 'BUB', 'cabezas_requeridas' => 0, 'capacidad' => 'sin_dato'])
        ->and($primero['compatibilidad']['distancia_km'])->toBeGreaterThan(150)->toBeLessThan(300)
        ->and($primero['zona']['localidad'])->toBeNull() // el anunciante no eligió mostrarla
        ->and($primero['zona']['provincia'])->toBe('Corrientes')
        ->and($primero['publicado_por']['establecimiento'])->toBe('La Esperanza')
        ->and($r['data'][1]['compatibilidad']['compatible'])->toBeFalse();
    // Sin coordenadas en la respuesta pública.
    expect(json_encode($r))->not->toContain('lat')->not->toContain('lon')->not->toContain('lindero');
    $this->getJson("/api/v1/campos?establecimiento_id={$this->otro->id}&solo_compatibles=1")->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/campos?establecimiento_id={$this->otro->id}&solo_compatibles=1&cabezas=80")->assertJsonPath('data.0.compatibilidad.puntaje', 100)
        ->assertJsonPath('data.0.compatibilidad.capacidad', 'suficiente');
    $this->getJson("/api/v1/campos?establecimiento_id={$this->otro->id}&solo_compatibles=1&cabezas=500")->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/campos?modalidad=arrendamiento')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.compatibilidad', null);
    $this->getJson('/api/v1/campos?especie=BUB&cabezas=100')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/campos?provincia=22')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/campos?desde='.now()->addYear()->toDateString())->assertJsonPath('meta.total', 0);

    $d = $this->getJson("/api/v1/campos/{$a['id']}?establecimiento_id={$this->otro->id}")->assertOk()->json('data');
    expect($d['checklist'])->not->toBeEmpty()->and($d['avisos'][0])->toContain('no inspecciona')->and($d['mi_consulta'])->toBeNull();

    // Consulta: sin consentimiento 422; con consentimiento 201 y aviso al anunciante.
    $body = ['establecimiento_id' => $this->otro->id, 'mensaje' => 'Tengo 80 bubillos para engorde desde noviembre.', 'especie' => 'BUB', 'cabezas' => 80, 'categorias' => ['BUB_BUBILLO']];
    $this->postJson("/api/v1/campos/{$a['id']}/consultas", $body)->assertUnprocessable()->assertJsonValidationErrors('consentimiento');
    $c = $this->postJson("/api/v1/campos/{$a['id']}/consultas", [...$body, 'consentimiento' => true])->assertCreated()
        ->assertJsonPath('data.estado', 'enviada')->assertJsonPath('data.contacto', null)->json('data');
    $this->postJson("/api/v1/campos/{$a['id']}/consultas", [...$body, 'consentimiento' => true])->assertUnprocessable()->assertJsonPath('code', 'regla_bloqueante');
    expect(DB::table('notificaciones')->where('usuario_id', $this->dueno->id)->where('tipo', 'campo_consulta')->count())->toBe(1);

    // El anunciante ve el contacto del interesado y responde (comparte el suyo).
    Sanctum::actingAs($this->dueno);
    $rec = $this->getJson("{$this->base}/consultas/recibidas")->assertOk()->json('data.0');
    expect($rec['contacto']['email'])->toBe($this->interesado->email)->and($rec['interesado']['establecimiento'])->toBe('El Ceibo');
    $this->patchJson("{$this->base}/consultas/recibidas/{$c['id']}", ['estado' => 'respondida'])->assertUnprocessable();
    $this->patchJson("{$this->base}/consultas/recibidas/{$c['id']}", ['estado' => 'acuerdo'])->assertUnprocessable()->assertJsonPath('code', 'regla_bloqueante');
    $this->patchJson("{$this->base}/consultas/recibidas/{$c['id']}", ['estado' => 'respondida', 'respuesta' => 'Hay lugar. ¿Querés venir el sábado?'])->assertOk();
    $this->getJson("{$this->base}/avisos")->assertJsonPath('data.0.consultas_abiertas', 1);

    Sanctum::actingAs($this->interesado);
    $env = $this->getJson("/api/v1/establecimientos/{$this->otro->id}/campos/consultas/enviadas")->assertOk()->json('data.0');
    expect($env['contacto']['email'])->toBe($this->dueno->email)->and($env['respuesta'])->toContain('sábado')->and($env['publicado_por'])->toBe('La Esperanza');
    expect(DB::table('notificaciones')->where('usuario_id', $this->interesado->id)->where('tipo', 'campo_respuesta')->count())->toBe(1);
    $this->patchJson("/api/v1/establecimientos/{$this->otro->id}/campos/consultas/enviadas/{$c['id']}", ['estado' => 'visita_coordinada'])->assertOk();
    $this->patchJson("/api/v1/establecimientos/{$this->otro->id}/campos/consultas/enviadas/{$c['id']}", ['estado' => 'acuerdo', 'nota' => 'Entran el 1/11'])->assertOk()
        ->assertJsonPath('data.estado', 'acuerdo');

    // El anunciante cierra el aviso con motivo (métrica del piloto).
    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/avisos/{$a['id']}/cerrar")->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson("{$this->base}/avisos/{$a['id']}/cerrar", ['motivo' => 'acuerdo_tropilla'])->assertOk()->assertJsonPath('data.estado', 'cerrado');
    $this->patchJson("{$this->base}/avisos/{$a['id']}", ['row_version' => 99, 'titulo' => 'Nuevo título'])->assertStatus(409);
    expect(DB::table('auditoria')->where('entidad', 'campo_aviso')->where('entidad_id', $a['id'])->pluck('accion')->all())->toBe(['crear', 'publicar', 'cerrar']);
});

it('disputa: reporte anónimo para el anunciante, descargo, en revisión con N reportes y resolución del staff', function () {
    config(['tropilla.campos.umbral_revision' => 2]);
    $a = $this->postJson("{$this->base}/avisos", avisoCampo())->assertCreated()->json('data');
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertOk();
    // El propio anunciante no puede reportarse.
    $this->postJson("/api/v1/campos/{$a['id']}/disputas", ['motivo' => 'otro', 'detalle' => 'Prueba de autorreporte del aviso propio.'])->assertUnprocessable();

    Sanctum::actingAs($this->interesado);
    $this->postJson("/api/v1/campos/{$a['id']}/disputas", ['motivo' => 'datos_falsos', 'detalle' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('detalle');
    $x = $this->postJson("/api/v1/campos/{$a['id']}/disputas", ['motivo' => 'datos_falsos', 'detalle' => 'Fui a ver el campo y no tiene tajamar ni manga.'])->assertCreated()
        ->assertJsonPath('data.estado', 'abierta')->assertJsonMissingPath('data.denunciante')->json('data');
    $this->postJson("/api/v1/campos/{$a['id']}/disputas", ['motivo' => 'otro', 'detalle' => 'Insisto con el mismo reporte de antes.'])->assertUnprocessable();
    $this->getJson("/api/v1/campos/{$a['id']}")->assertJsonPath('data.reportado', true);

    Sanctum::actingAs($this->dueno);
    $show = $this->getJson("{$this->base}/avisos/{$a['id']}")->assertOk()->json('data');
    expect($show['disputas_abiertas'])->toBe(1)->and($show['disputas'][0])->not->toHaveKey('denunciante')
        ->and(DB::table('notificaciones')->where('usuario_id', $this->dueno->id)->where('tipo', 'campo_disputa')->count())->toBe(1);
    $this->postJson("{$this->base}/avisos/{$a['id']}/disputas/{$x['id']}/descargo", ['descargo' => 'El tajamar está en el potrero del fondo; los invito a recorrerlo.'])->assertOk()
        ->assertJsonPath('data.descargo', 'El tajamar está en el potrero del fondo; los invito a recorrerlo.');

    // Segundo reporte de otra persona → en revisión (deja de verse en el mercado).
    Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/v1/campos/{$a['id']}/disputas", ['motivo' => 'aviso_inexistente', 'detalle' => 'Llamé y me dijeron que el campo ya estaba ocupado.'])->assertCreated();
    expect(DB::table('campo_avisos')->where('id', $a['id'])->value('estado'))->toBe('en_revision');
    $this->getJson('/api/v1/campos')->assertJsonPath('meta.total', 0);
    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertUnprocessable()->assertJsonPath('message', 'El aviso está en revisión por un reporte. El equipo de TROPILLA te va a contactar.');

    // Moderación: 403 sin staff; el staff ve la identidad y resuelve.
    $this->getJson('/api/v1/staff/campos/disputas')->assertForbidden();
    $staff = User::factory()->create(['es_staff' => true]);
    Sanctum::actingAs($staff);
    $pend = $this->getJson('/api/v1/staff/campos/disputas')->assertOk()->json('data');
    expect($pend)->toHaveCount(2)->and($pend[0]['denunciante']['email'])->toBe($this->interesado->email);
    $this->postJson("/api/v1/staff/campos/disputas/{$pend[0]['id']}/resolver", ['estado' => 'desestimada'])->assertUnprocessable()->assertJsonValidationErrors('resolucion');
    $this->postJson("/api/v1/staff/campos/disputas/{$pend[0]['id']}/resolver", ['estado' => 'desestimada', 'resolucion' => 'Visitamos el campo con el anunciante: el tajamar existe.'])->assertOk();
    expect(DB::table('campo_avisos')->where('id', $a['id'])->value('estado'))->toBe('en_revision'); // queda otro reporte abierto
    $this->postJson("/api/v1/staff/campos/disputas/{$pend[1]['id']}/resolver", ['estado' => 'resuelta', 'resolucion' => 'El anunciante confirmó disponibilidad parcial.', 'accion' => 'ninguna'])->assertOk();
    expect(DB::table('campo_avisos')->where('id', $a['id'])->value('estado'))->toBe('publicado')
        ->and(DB::table('notificaciones')->where('usuario_id', $this->interesado->id)->where('tipo', 'campo_disputa')->count())->toBe(1);

    // Verificación por staff; si el anunciante cambia la capacidad, vuelve a «declarado».
    $this->putJson("/api/v1/staff/campos/avisos/{$a['id']}/verificacion", ['nivel' => 'contacto_verificado', 'nota' => 'Llamada al dueño el 17/10, confirmó el aviso.'])->assertOk()
        ->assertJsonPath('data.verificacion.nivel', 'contacto_verificado');
    Sanctum::actingAs($this->dueno);
    $rv = $this->getJson("{$this->base}/avisos/{$a['id']}")->json('data.row_version');
    $this->patchJson("{$this->base}/avisos/{$a['id']}", ['row_version' => $rv, 'titulo' => 'Pastaje en bajos (actualizado)'])->assertOk()->assertJsonPath('data.verificacion.nivel', 'contacto_verificado');
    $this->patchJson("{$this->base}/avisos/{$a['id']}", ['row_version' => $rv + 1, 'capacidad_cabezas' => 300])->assertOk()->assertJsonPath('data.verificacion.nivel', 'declarado');
});

it('permisos, piloto regional y validaciones', function () {
    // 422: fuera del piloto, departamento de otra provincia, precio faltante, temporada invertida, categoría de otra especie.
    $this->postJson("{$this->base}/avisos", avisoCampo(['provincia_codigo' => '06', 'departamento_codigo' => '06007']))->assertUnprocessable()->assertJsonValidationErrors('provincia_codigo');
    $this->postJson("{$this->base}/avisos", avisoCampo(['departamento_codigo' => '22140']))->assertUnprocessable()->assertJsonValidationErrors('departamento_codigo');
    $this->postJson("{$this->base}/avisos", avisoCampo(['precio_valor' => null]))->assertUnprocessable()->assertJsonValidationErrors('precio_valor');
    $this->postJson("{$this->base}/avisos", avisoCampo(['temporada_hasta' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('temporada_hasta');
    $this->postJson("{$this->base}/avisos", avisoCampo(['especies' => ['BUB'], 'categorias' => ['BOV_VACA']]))->assertUnprocessable()->assertJsonValidationErrors('categorias');
    $this->postJson("{$this->base}/avisos", avisoCampo(['modalidad' => 'venta']))->assertUnprocessable()->assertJsonValidationErrors('modalidad');
    $a = $this->postJson("{$this->base}/avisos", avisoCampo())->assertCreated()->json('data');

    // Otro establecimiento: ni gestiona ni ve mis avisos (404, no se revela la existencia); encargado: 403.
    Sanctum::actingAs($this->interesado);
    $this->getJson("{$this->base}/avisos")->assertNotFound();
    $this->postJson("{$this->base}/avisos", avisoCampo())->assertNotFound();
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertNotFound();
    $this->getJson("/api/v1/campos?establecimiento_id={$this->est->id}")->assertNotFound();
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/avisos")->assertForbidden();
    Sanctum::actingAs($asesor = miembro($this->est, 'asesor'));
    $this->getJson("{$this->base}/avisos")->assertOk();
    $this->postJson("{$this->base}/avisos", avisoCampo())->assertForbidden();

    // Publicar exige email verificado.
    Sanctum::actingAs($this->dueno->forceFill(['email_verificado_en' => null]));
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertForbidden()->assertJsonPath('code', 'email_no_verificado');
    $this->dueno->forceFill(['email_verificado_en' => now()]);
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertOk();
    $this->postJson("{$this->base}/avisos/{$a['id']}/pausar")->assertOk()->assertJsonPath('data.estado', 'pausado');
    $this->postJson("{$this->base}/avisos/{$a['id']}/pausar")->assertUnprocessable();

    // Consultar: aviso pausado no; especie que no recibe tampoco; desde un establecimiento ajeno 403.
    Sanctum::actingAs($this->interesado);
    $body = ['establecimiento_id' => $this->otro->id, 'mensaje' => 'Consulta de prueba por el campo.', 'especie' => 'BUB', 'cabezas' => 10, 'consentimiento' => true];
    $this->postJson("/api/v1/campos/{$a['id']}/consultas", $body)->assertUnprocessable()->assertJsonPath('message', 'El aviso no está publicado.');
    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/avisos/{$a['id']}/publicar")->assertOk();
    $this->postJson("/api/v1/campos/{$a['id']}/consultas", [...$body, 'establecimiento_id' => $this->est->id])->assertUnprocessable()
        ->assertJsonPath('message', 'No podés consultar un aviso de tu propio establecimiento.');
    Sanctum::actingAs($this->interesado);
    $this->postJson("/api/v1/campos/{$a['id']}/consultas", [...$body, 'establecimiento_id' => $this->est->id])->assertNotFound();
    $this->patchJson("/api/v1/establecimientos/{$this->est->id}/campos/consultas/recibidas/0191e7a4-0000-7000-8000-000000000000", ['estado' => 'respondida'])->assertNotFound();
    Sanctum::actingAs($asesor);
    $this->patchJson("/api/v1/establecimientos/{$this->est->id}/campos/consultas/recibidas/0191e7a4-0000-7000-8000-000000000000", ['estado' => 'respondida'])->assertForbidden();

    $cond = $this->getJson('/api/v1/campos/condiciones')->assertOk()->json('data');
    expect(collect($cond['provincias_piloto'])->pluck('nombre')->all())->toBe(['Corrientes', 'Chaco', 'Formosa', 'Misiones'])
        ->and(collect($cond['modalidades'])->pluck('codigo')->all())->toBe(['pastaje', 'arrendamiento', 'capitalizacion']);
});
