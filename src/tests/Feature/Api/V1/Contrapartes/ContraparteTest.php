<?php

use App\Domain\Contrapartes\Cuit;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const CUIT_CON_DATOS = '30500010912';
const CUIT_SIN_DATOS = '30712345671';
const CUIT_HUMANA = '20123456786';

function fakeBcra(): void
{
    $base = 'api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas';
    $noHay = Http::response(['status' => 404, 'errorMessages' => ['No se encontró datos para la identificación ingresada.']], 404);
    Http::fake([
        "{$base}/Historicas/".CUIT_CON_DATOS => Http::response(['status' => 200, 'results' => ['identificacion' => (int) CUIT_CON_DATOS, 'denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'periodos' => [
            ['periodo' => '202608', 'entidades' => [['entidad' => 'BANCO A', 'situacion' => 1, 'monto' => 1200.0], ['entidad' => 'BANCO B', 'situacion' => 3, 'monto' => 300.0]]],
            ['periodo' => '202607', 'entidades' => [['entidad' => 'BANCO A', 'situacion' => 1, 'monto' => 1100.0]]],
        ]]]),
        "{$base}/ChequesRechazados/".CUIT_CON_DATOS => Http::response(['status' => 200, 'results' => ['identificacion' => (int) CUIT_CON_DATOS, 'denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'causales' => [
            ['causal' => 'SIN FONDOS', 'entidades' => [['entidad' => 11, 'detalle' => [
                ['nroCheque' => 123, 'fechaRechazo' => '2026-05-02', 'monto' => 250000.0, 'fechaPago' => '2026-05-20', 'fechaPagoMulta' => null, 'estadoMulta' => 'IMPAGA', 'enRevision' => false, 'procesoJud' => false],
                ['nroCheque' => 124, 'fechaRechazo' => '2026-06-10', 'monto' => 180000.0, 'fechaPago' => null, 'fechaPagoMulta' => null, 'estadoMulta' => 'IMPAGA', 'enRevision' => false, 'procesoJud' => false],
            ]]]],
        ]]]),
        "{$base}/".CUIT_CON_DATOS => Http::response(['status' => 200, 'results' => ['identificacion' => (int) CUIT_CON_DATOS, 'denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'periodos' => [
            ['periodo' => '202608', 'entidades' => [
                ['entidad' => 'BANCO A', 'situacion' => 1, 'monto' => 1200.0, 'diasAtrasoPago' => 0, 'refinanciaciones' => false, 'situacionJuridica' => false, 'procesoJud' => false, 'enRevision' => false],
                ['entidad' => 'BANCO B', 'situacion' => 3, 'monto' => 300.0, 'diasAtrasoPago' => 95, 'refinanciaciones' => false, 'situacionJuridica' => false, 'procesoJud' => false, 'enRevision' => true],
            ]],
        ]]]),
        "{$base}/*" => $noHay,
    ]);
}

beforeEach(function () {
    Cache::flush();
    fakeBcra();
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}/contrapartes";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
});

it('valida CUIT con dígito verificador y tipo de persona', function () {
    expect(Cuit::valido('30-50001091-2'))->toBeTrue()
        ->and(Cuit::valido('30500010913'))->toBeFalse()
        ->and(Cuit::valido(CUIT_HUMANA))->toBeTrue()
        ->and(Cuit::tipoPersona(CUIT_HUMANA))->toBe('humana')
        ->and(Cuit::formatear(CUIT_CON_DATOS))->toBe('30-50001091-2');
});

it('consulta asistida: registra finalidad y consentimiento, informa fuente/fecha y lectura neutral sin score', function () {
    $f = $this->getJson("{$this->base}/fuentes")->assertOk()->json('data');
    expect(collect($f['fuentes'])->pluck('codigo')->all())->toBe(['bcra_central_deudores', 'arca_constancia'])
        ->and($f['revision_legal'])->toBe('pendiente')
        ->and($f['checklist'])->toHaveCount(7);

    $c = $this->postJson("{$this->base}/consultas", ['cuit' => '30-50001091-2', 'finalidad' => 'venta_plazo', 'consentimiento' => true])->assertCreated()->json('data');
    expect(DB::table('auditoria')->where('entidad', 'consulta_contraparte')->count())->toBe(1);

    expect($c)->toMatchArray(['cuit' => '30-50001091-2', 'tipo_persona' => 'juridica', 'denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'finalidad' => 'venta_plazo', 'checklist_hechos' => 0])
        ->and($c['consentimiento'])->toMatchArray(['acepta' => true, 'version' => '2026-10-17']);
    $i = $c['informe'];
    $bcra = collect($i['fuentes'])->firstWhere('codigo', 'bcra_central_deudores');
    $arca = collect($i['fuentes'])->firstWhere('codigo', 'arca_constancia');
    expect($bcra['estado'])->toBe('ok')->and($bcra['consultado_en'])->not->toBeNull()
        ->and($arca['estado'])->toBe('no_configurada')
        ->and($i['bcra']['actual'])->toMatchArray(['periodo' => '2026-08', 'peor_situacion' => 3])
        ->and($i['bcra']['historial']['meses_con_situacion_2_o_mas'])->toBe(1)
        ->and($i['bcra']['cheques'])->toMatchArray(['total' => 2, 'sin_pago_informado' => 1]);
    $texto = implode(' ', $i['lectura']);
    expect($texto)->toContain('Al período 2026-08, 2 entidades informan')->toContain('BANCO B informa Situación 3')->toContain('95 días de atraso')
        ->toContain('figura en revisión')->toContain('Figuran 2 cheques rechazados');
    // Lenguaje: sin calificaciones ni puntajes.
    expect(json_encode($i, JSON_UNESCAPED_UNICODE))->not->toContain('score')->not->toContain('moroso')->not->toContain('riesgoso')->not->toContain('no vender');
    expect($i['senales_desactivadas'][0])->toContain('Antigüedad del CUIT');

    // Checklist manual y listado
    $this->putJson("{$this->base}/consultas/{$c['id']}/checklist/identidad", ['estado' => 'hecho', 'nota' => 'Coincide con la factura'])->assertOk()
        ->assertJsonPath('data.checklist_hechos', 1)->assertJsonPath('data.checklist.0.nota', 'Coincide con la factura');
    $this->putJson("{$this->base}/consultas/{$c['id']}/checklist/inexistente", ['estado' => 'hecho'])->assertNotFound();
    $this->putJson("{$this->base}/consultas/{$c['id']}/checklist/ruca", ['estado' => 'quizas'])->assertUnprocessable();
    $lista = $this->getJson("{$this->base}/consultas")->assertOk()->json('data');
    expect($lista)->toHaveCount(1)->and($lista[0])->not->toHaveKey('informe')->and($lista[0]['consultado_por'])->toBe($this->dueno->nombre);
});

it('sin registros en el BCRA informa «sin datos» sin decir que no hay deudas', function () {
    $c = $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_SIN_DATOS, 'finalidad' => 'cobro_cheque', 'consentimiento' => true])->assertCreated()->json('data');
    expect(collect($c['informe']['fuentes'])->firstWhere('codigo', 'bcra_central_deudores')['estado'])->toBe('sin_datos')
        ->and($c['informe']['lectura'][0])->toContain('No significa que no tenga deudas')
        ->and($c['informe']['bcra'])->toBeNull();
});

it('si el BCRA no responde lo informa como error de la fuente y no lo cachea', function () {
    Http::fake(['api.bcra.gob.ar/*' => fn () => throw new ConnectionException('timeout')]);
    $c = $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_CON_DATOS, 'finalidad' => 'venta_contado', 'consentimiento' => true])->assertCreated()->json('data');
    expect(collect($c['informe']['fuentes'])->firstWhere('codigo', 'bcra_central_deudores')['estado'])->toBe('error')
        ->and($c['informe']['lectura'][0])->toContain('Esto no dice nada sobre la contraparte')
        ->and(Cache::has('contrapartes:bcra:'.CUIT_CON_DATOS))->toBeFalse();
});

it('exige consentimiento, finalidad, CUIT válido y autorización del titular si es persona humana', function () {
    $this->postJson("{$this->base}/consultas", ['cuit' => '30500010913', 'finalidad' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['finalidad', 'consentimiento']);
    $this->postJson("{$this->base}/consultas", ['cuit' => '30500010913', 'finalidad' => 'venta_plazo', 'consentimiento' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['cuit']);
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_HUMANA, 'finalidad' => 'venta_plazo', 'consentimiento' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['titular_autorizo']);
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_HUMANA, 'finalidad' => 'otra', 'consentimiento' => true, 'titular_autorizo' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['finalidad_detalle']);
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_HUMANA, 'finalidad' => 'venta_plazo', 'consentimiento' => true, 'titular_autorizo' => true])
        ->assertCreated()->assertJsonPath('data.consentimiento.titular_autorizo', true);
});

it('limita consultas por día, restringe a dueño/admin y responde 404 en un establecimiento ajeno', function () {
    config(['tropilla.contrapartes.max_consultas_dia' => 1]);
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_SIN_DATOS, 'finalidad' => 'venta_plazo', 'consentimiento' => true])->assertCreated();
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_SIN_DATOS, 'finalidad' => 'venta_plazo', 'consentimiento' => true])
        ->assertStatus(429)->assertJsonPath('code', 'demasiados_intentos');

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/consultas")->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_SIN_DATOS, 'finalidad' => 'venta_plazo', 'consentimiento' => true])->assertForbidden();

    $ajeno = crearEstablecimiento(extra: ['nombre' => 'Otro']);
    Sanctum::actingAs($this->dueno);
    $this->getJson("/api/v1/establecimientos/{$ajeno->id}/contrapartes/consultas")->assertNotFound();
});

it('calcula un semáforo desde reglas versionadas y señala cheque denunciado contra BCRA', function () {
    config(['tropilla.extraccion.driver' => 'fake']);
    FakeExtractor::reset();
    FakeExtractor::$salida = ['texto' => 'La situación informada merece atención.', 'senales_ids' => ['bcra_situacion_amarilla', 'cheques_rechazados_amarillo']];
    Http::fake([
        'api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas/30500010912' => Http::response(['status' => 200, 'results' => ['denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'periodos' => [['periodo' => '202608', 'entidades' => [['entidad' => 'BANCO A', 'situacion' => 4, 'monto' => 500.0]]]]]]),
        'api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas/Historicas/30500010912' => Http::response(['status' => 200, 'results' => ['periodos' => []]]),
        'api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas/ChequesRechazados/30500010912' => Http::response(['status' => 200, 'results' => ['causales' => []]]),
        'api.bcra.gob.ar/cheques/v1.0/entidades' => Http::response(['status' => 200, 'results' => [['codigoEntidad' => 11, 'denominacion' => 'BANCO DE LA NACION ARGENTINA']]]),
        'api.bcra.gob.ar/cheques/v1.0/denunciados/11/507' => Http::response(['status' => 200, 'results' => ['numeroCheque' => 507, 'denunciado' => true, 'fechaProcesamiento' => '2026-10-16', 'denominacionEntidad' => 'BANCO DE LA NACION ARGENTINA', 'detalles' => [['sucursal' => 17, 'numeroCuenta' => 170061227, 'causal' => 'Denunciado por titular']]]]),
        'api.bcra.gob.ar/*' => Http::response(['status' => 404], 404),
    ]);
    $c = $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_CON_DATOS, 'finalidad' => 'cobro_cheque', 'consentimiento' => true])->assertCreated()->json('data');
    expect($c['informe']['semaforo']['nivel'])->toBe('amarillo')
        ->and($c['informe']['semaforo']['completo'])->toBeTrue()
        ->and($c['informe']['semaforo']['resumen']['proveedor'])->toBe('fake')
        ->and($c['informe']['semaforo']['senales'])->toBeArray()
        ->and(DB::table('contraparte_reglas')->where('codigo', 'cheque_denunciado_rojo')->value('version'))->toBe(1);
    $this->postJson("{$this->base}/cheques/verificar", ['banco_codigo' => 11, 'numero' => 507, 'cuenta' => '170061227'])->assertOk()
        ->assertJsonPath('data.datos.denunciado', true)->assertJsonPath('data.datos.cuenta_consultada', '••••1227')
        ->assertJsonPath('data.datos.detalles.0.numeroCuenta', '••••1227');
    $this->postJson("{$this->base}/cheques/verificar", ['banco_codigo' => 11, 'numero' => 507, 'cuenta' => '999'])->assertOk()
        ->assertJsonPath('data.datos.denunciado', false);
    expect(DB::table('auditoria')->where('entidad', 'consulta_cheque')->count())->toBe(2);
});

it('no transforma un BCRA sin registros en semáforo verde ni en ausencia de deudas', function () {
    $c = $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_SIN_DATOS, 'finalidad' => 'cobro_cheque', 'consentimiento' => true])->assertCreated()->json('data');
    expect($c['informe']['semaforo']['completo'])->toBeFalse()
        ->and($c['informe']['semaforo']['nivel'])->toBe('amarillo')
        ->and(collect($c['informe']['semaforo']['senales'])->firstWhere('codigo', 'fuente_no_disponible')['descripcion'])->toContain('no indica ausencia de deudas');
});

it('exige CUIT, consentimiento y razón social declarada en el alta del comprador', function () {
    Http::fake(['api.bcra.gob.ar/CentralDeDeudores/v1.0/Deudas/*' => Http::response(['status' => 200, 'results' => ['denominacion' => 'AGROPECUARIA EL OMBÚ S.A.', 'periodos' => [['periodo' => '202608', 'entidades' => []]]]])]);
    $request = ['empresa' => 'Agropecuaria El Ombú S.A.', 'tipo' => 'productor', 'pais' => 'AR', 'cuit' => '30-50001091-2', 'razon_social' => 'AGROPECUARIA EL OMBÚ S.A.', 'consentimiento_cuit' => true];
    $r = $this->putJson('/api/v1/me/comprador', $request)->assertOk()->json('data');
    expect($r['validacion_cuit']['estado'])->toBe('validado')->and($r['validacion_cuit']['fuente'])->toBe('bcra_central_deudores');
    $this->assertDatabaseHas('compradores', ['usuario_id' => $this->dueno->id, 'validacion_cuit_estado' => 'validado']);
    $this->putJson('/api/v1/me/comprador', [...$request, 'cuit' => '30500010913'])->assertUnprocessable()->assertJsonValidationErrors('cuit');
    $this->putJson('/api/v1/me/comprador', [...$request, 'consentimiento_cuit' => false])->assertUnprocessable()->assertJsonValidationErrors('consentimiento_cuit');
});

it('revalida perfiles compradores consentidos y audita la fuente sin exponer el CUIT', function () {
    $request = ['empresa' => 'Agropecuaria El Ombú S.A.', 'tipo' => 'productor', 'pais' => 'AR', 'cuit' => CUIT_CON_DATOS, 'razon_social' => 'AGROPECUARIA EL OMBÚ S.A.', 'consentimiento_cuit' => true];
    $this->putJson('/api/v1/me/comprador', $request)->assertOk();
    Cache::forget('contrapartes:bcra:'.CUIT_CON_DATOS);
    $this->travel(31)->days();

    $this->artisan('compradores:revalidar-cuit')->assertSuccessful();

    $this->assertDatabaseHas('compradores', ['usuario_id' => $this->dueno->id, 'validacion_cuit_estado' => 'validado', 'consentimiento_cuit' => true]);
    $auditoria = DB::table('auditoria')->where('entidad', 'validacion_cuit_comprador')->latest('created_at')->first();
    expect($auditoria)->not->toBeNull()
        ->and($auditoria->despues)->toContain(hash('sha256', CUIT_CON_DATOS))
        ->and($auditoria->despues)->not->toContain(CUIT_CON_DATOS);
});

it('depura el informe vencida la retención y conserva el registro de la consulta', function () {
    $c = $this->postJson("{$this->base}/consultas", ['cuit' => CUIT_CON_DATOS, 'finalidad' => 'venta_plazo', 'consentimiento' => true])->assertCreated()->json('data');
    $this->travel(181)->days();
    $this->artisan('contrapartes:depurar')->assertSuccessful();

    $d = $this->getJson("{$this->base}/consultas/{$c['id']}")->assertOk()->json('data');
    expect($d['informe'])->toBeNull()->and($d['denominacion'])->toBeNull()->and($d['depurada_en'])->not->toBeNull()
        ->and($d['finalidad'])->toBe('venta_plazo')->and($d['cuit'])->toBe('30-50001091-2');
    expect(DB::table('contraparte_consultas')->count())->toBe(1);
});
