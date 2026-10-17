<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    config(['tropilla.archivos.driver' => 'local', 'tropilla.extraccion.driver' => 'fake']);
    FakeExtractor::reset();
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento(extra: ['renspa' => '18.105.0.00001/00']);
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->vacas = array_map(fn ($n) => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'identificaciones' => [['tipo' => 'caravana_oficial', 'valor' => "AR-00{$n}"]]]), [1, 2, 3]);
    $this->bufala = animalNuevo(['identificaciones' => [['tipo' => 'rfid', 'valor' => '032000000000123']]]);
    $this->postJson("{$this->base}/eventos", altaBody([...$this->vacas, $this->bufala], '2026-01-10'))->assertCreated();
});

function documentoSanitario(object $t, string $tipo = 'analisis_laboratorio'): string
{
    $bytes = 'protocolo '.Str::random(8);
    $sha = app(GuardarArchivo::class)->handle($bytes, 'image/jpeg', $t->dueno);

    return $t->postJson("{$t->base}/documentos", ['tipo' => $tipo, 'titulo' => 'Protocolo de laboratorio',
        'archivos' => [['sha256' => $sha, 'bytes' => strlen($bytes), 'mime' => 'image/jpeg']]])->assertCreated()->json('data.id');
}

function protocoloBrucelosis(object $t, array $extra = []): array
{
    return array_replace([
        'id' => (string) Str::uuid7(), 'enfermedad' => 'brucelosis', 'prueba_codigo' => 'brucelosis_bpa', 'motivo' => 'movimiento',
        'fecha_muestreo' => '2026-09-20', 'fecha_resultado' => '2026-09-23', 'laboratorio' => 'Lab. Regional Mercedes', 'protocolo_numero' => 'P-4411',
        'veterinario_nombre' => 'Dra. Ana Ríos', 'veterinario_matricula' => 'MP 1234', 'origen' => 'declarado',
        'resultados' => [
            ['animal_id' => $t->vacas[0]['animal_id'], 'muestra' => 'T1', 'resultado' => 'negativo'],
            ['identificacion' => 'AR-2', 'muestra' => 'T2', 'resultado' => 'negativo'],
            ['identificacion' => 'AR003', 'muestra' => 'T3', 'resultado' => 'positivo'],
            ['identificacion' => 'AR-999', 'muestra' => 'T4', 'resultado' => 'negativo'],
        ],
    ], $extra);
}

it('protocolo de brucelosis: asocia por identificación, pide confirmar, registra pruebas por animal, reactor, vence a 60 días y se anula', function () {
    $body = protocoloBrucelosis($this);
    $r = $this->postJson("{$this->base}/sanidad/protocolos", $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['animal_no_asociado', 'marca_reactor']);

    $p = $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.vence_el', '2026-11-19')->assertJsonPath('data.vigencia', 'vigente')->assertJsonPath('data.origen', 'declarado')
        ->assertJsonPath('data.resumen.total', 4)->assertJsonPath('data.resumen.asociados', 3)->assertJsonPath('data.resumen.positivo', 1)
        ->assertJsonPath('data.resultados.1.animal_id', $this->vacas[1]['animal_id'])->assertJsonPath('data.resultados.3.animal_id', null)
        ->assertJsonPath('data.resultados.3.identificacion', 'AR-999')->json('data');
    expect($p['evento_ids'])->toHaveCount(2); // negativos agrupados + positivo
    $vaca3 = DB::table('animales_estado')->where('animal_id', $this->vacas[2]['animal_id'])->first();
    expect($vaca3->reactor)->toBeTrue()->and($vaca3->reactor_prueba)->toBe('brucelosis_bpa');
    $ev = DB::table('eventos')->where('id', $p['resultados'][0]['evento_id'])->first();
    expect(json_decode($ev->payload, true))->toMatchArray(['prueba' => 'brucelosis_bpa', 'resultado' => 'negativo', 'protocolo_numero' => 'P-4411', 'resultado_origen' => 'declarado']);

    // Idempotente por id; lista con filtros; ficha del animal.
    $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'confirmar_advertencias' => true])->assertOk();
    $this->getJson("{$this->base}/sanidad/protocolos?enfermedad=brucelosis")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/sanidad/protocolos?enfermedad=tuberculosis")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/animales/{$this->vacas[2]['animal_id']}/pruebas")->assertOk()
        ->assertJsonPath('data.0.resultado', 'positivo')->assertJsonPath('data.0.prueba_nombre', 'Brucelosis BPA')->assertJsonPath('data.0.origen', 'declarado');

    // Resumen: positivos y vencimiento del resultado para movimiento.
    $res = $this->getJson("{$this->base}/sanidad/oficial")->assertOk()->json('data');
    expect($res['enfermedades'][0]['positivos'][0]['animal_id'])->toBe($this->vacas[2]['animal_id'])
        ->and($res['vencimientos'][0])->toMatchArray(['tipo' => 'protocolo', 'enfermedad' => 'brucelosis', 'fecha' => '2026-11-19', 'dias' => 49])
        ->and($res['aviso'])->toContain('no es la condición sanitaria oficial');

    // Anular: anula los eventos y el animal deja de ser reactor.
    $this->postJson("{$this->base}/sanidad/protocolos/{$p['id']}/anular", ['motivo' => 'Cargado en el establecimiento equivocado'])->assertOk()->assertJsonPath('data.estado', 'anulado');
    expect(DB::table('animales_estado')->where('animal_id', $this->vacas[2]['animal_id'])->value('reactor'))->toBeFalse()
        ->and(DB::table('eventos')->where('evento_raiz_id', $p['evento_ids'][0])->where('accion', 'anulacion')->exists())->toBeTrue();
    $this->getJson("{$this->base}/sanidad/protocolos")->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/animales/{$this->vacas[2]['animal_id']}/pruebas")->assertJsonCount(0, 'data');
    // Los resultados son append-only.
    expect(fn () => DB::table('protocolo_resultados')->where('protocolo_id', $p['id'])->delete())->toThrow(Exception::class);
});

it('tuberculina con lecturas: un evento por animal; transcripción revisada exige el original; 422 de validación', function () {
    $docId = documentoSanitario($this, 'informe_tuberculina');
    $body = ['enfermedad' => 'tuberculosis', 'prueba_codigo' => 'tuberculina_anocaudal', 'motivo' => 'does', 'modalidad' => 'total', 'stock_base' => 4,
        'fecha_muestreo' => '2026-09-01', 'fecha_resultado' => '2026-09-04', 'origen' => 'transcripcion_revisada',
        'resultados' => [
            ['animal_id' => $this->vacas[0]['animal_id'], 'lectura_0h_mm' => '4', 'lectura_72h_mm' => '5.5', 'resultado' => 'negativo'],
            ['animal_id' => $this->bufala['animal_id'], 'lectura_0h_mm' => '4', 'lectura_72h_mm' => '6', 'resultado' => 'negativo'],
        ]];
    $this->postJson("{$this->base}/sanidad/protocolos", $body)->assertStatus(422)->assertJsonValidationErrors(['documento_id']);
    $r = $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'documento_id' => $docId])->assertStatus(422);
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['muestreo_incompleto']);
    $p = $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'documento_id' => $docId, 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.vence_el', null)->assertJsonPath('data.vigencia', 'sin_vencimiento')->assertJsonPath('data.resultados.1.lectura_72h_mm', '6')
        ->assertJsonPath('data.origen_leyenda', 'Transcripción revisada del original adjunto')->json('data');
    expect($p['evento_ids'])->toHaveCount(2);

    // Validación.
    $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'documento_id' => $docId, 'prueba_codigo' => 'brucelosis_bpa'])->assertStatus(422)->assertJsonValidationErrors(['prueba_codigo']);
    $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'enfermedad' => 'aftosa', 'resultados' => []])->assertStatus(422)
        ->assertJsonValidationErrors(['enfermedad', 'resultados']);
    $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'documento_id' => $docId, 'resultados' => [['animal_id' => $this->vacas[0]['animal_id'], 'lectura_0h_mm' => '4,5', 'resultado' => 'negativo']]])
        ->assertStatus(422)->assertJsonValidationErrors(['resultados.0.lectura_0h_mm', 'resultados.0.lectura_72h_mm']);
    $this->postJson("{$this->base}/sanidad/protocolos", [...$body, 'documento_id' => $docId, 'confirmar_advertencias' => true, 'resultados' => [
        ['animal_id' => $this->vacas[0]['animal_id'], 'resultado' => 'negativo'], ['identificacion' => 'AR-1', 'resultado' => 'negativo']]])
        ->assertStatus(422)->assertJsonValidationErrors(['resultados.1']);
});

it('estatus con certificado y vigencia, reemplazo e historial, plan de saneamiento y vencimientos', function () {
    $docId = documentoSanitario($this, 'certificado_sanitario');
    $this->postJson("{$this->base}/sanidad/estatus", ['enfermedad' => 'brucelosis', 'estatus' => 'libre', 'origen' => 'transcripcion_revisada', 'vigente_desde' => '2025-10-20'])
        ->assertStatus(422)->assertJsonValidationErrors(['documento_id']);
    $e1 = $this->postJson("{$this->base}/sanidad/estatus", ['enfermedad' => 'brucelosis', 'estatus' => 'libre', 'origen' => 'transcripcion_revisada', 'documento_id' => $docId,
        'certificado_numero' => 'CB-2025-118', 'autoridad' => 'SENASA Oficina Local Mercedes', 'emitido_el' => '2025-10-20', 'vigente_desde' => '2025-10-20', 'vence_el' => '2026-10-20'])
        ->assertCreated()->assertJsonPath('data.vigencia', 'por_vencer')->assertJsonPath('data.dias_para_vencer', 19)->json('data');
    $this->postJson("{$this->base}/sanidad/estatus", ['enfermedad' => 'brucelosis', 'estatus' => 'libre', 'origen' => 'declarado', 'vigente_desde' => '2026-01-01', 'vence_el' => '2025-12-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['vence_el']);

    // TBC en saneamiento con plan activo.
    $e2 = $this->postJson("{$this->base}/sanidad/estatus", ['enfermedad' => 'tuberculosis', 'estatus' => 'en_saneamiento', 'origen' => 'declarado', 'vigente_desde' => '2026-08-01'])
        ->assertCreated()->assertJsonPath('data.vigencia', 'sin_vencimiento')->json('data');
    $plan = $this->postJson("{$this->base}/sanidad/saneamiento", ['enfermedad' => 'tuberculosis', 'inicio' => '2026-08-01', 'proximo_muestreo' => '2026-11-01',
        'veterinario_nombre' => 'Dra. Ana Ríos', 'acciones' => [
            ['descripcion' => 'Faena de reactores', 'responsable' => 'Encargado', 'fecha_objetivo' => '2026-09-15'],
            ['descripcion' => 'Segunda prueba del rodeo', 'fecha_objetivo' => '2026-11-01'],
        ]])->assertCreated()->assertJsonPath('data.acciones_pendientes', 2)->assertJsonPath('data.acciones.0.vencida', true)->json('data');
    $this->postJson("{$this->base}/sanidad/saneamiento", ['enfermedad' => 'tuberculosis', 'inicio' => '2026-09-01'])->assertStatus(409)->assertJsonPath('code', 'plan_activo');

    $res = $this->getJson("{$this->base}/sanidad/oficial?horizonte_dias=60")->assertOk()->json('data');
    expect(collect($res['vencimientos'])->map(fn ($v) => [$v['tipo'], $v['fecha']])->all())
        ->toBe([['accion', '2026-09-15'], ['estatus', '2026-10-20'], ['accion', '2026-11-01'], ['muestreo', '2026-11-01']])
        ->and($res['vencimientos'][0]['vigencia'])->toBe('vencido')
        ->and($res['enfermedades'][1]['plan_saneamiento']['id'])->toBe($plan['id']);

    // Editar el plan con row_version (409 si quedó vieja) y cerrarlo.
    $acciones = [['descripcion' => 'Faena de reactores', 'fecha_objetivo' => '2026-09-15', 'hecha_el' => '2026-09-14'], ['descripcion' => 'Segunda prueba del rodeo', 'fecha_objetivo' => '2026-11-01']];
    $this->patchJson("{$this->base}/sanidad/saneamiento/{$plan['id']}", ['acciones' => $acciones, 'row_version' => 1])->assertOk()->assertJsonPath('data.acciones_pendientes', 1);
    $this->patchJson("{$this->base}/sanidad/saneamiento/{$plan['id']}", ['proximo_muestreo' => '2026-12-01', 'row_version' => 1])->assertStatus(409);
    $this->patchJson("{$this->base}/sanidad/saneamiento/{$plan['id']}", ['enfermedad' => 'brucelosis', 'row_version' => 2])->assertStatus(422)->assertJsonValidationErrors(['enfermedad']);
    $this->patchJson("{$this->base}/sanidad/saneamiento/{$plan['id']}", ['estado' => 'cumplido', 'row_version' => 2])->assertOk()->assertJsonPath('data.cerrado_el', '2026-10-01');

    // Nuevo estatus TBC libre reemplaza; anularlo devuelve el anterior.
    $e3 = $this->postJson("{$this->base}/sanidad/estatus", ['enfermedad' => 'tuberculosis', 'estatus' => 'libre', 'origen' => 'declarado', 'vigente_desde' => '2026-10-01', 'vence_el' => '2027-10-01'])
        ->assertCreated()->json('data');
    $tbc = $this->getJson("{$this->base}/sanidad/oficial")->json('data.enfermedades.1');
    expect($tbc['estatus']['id'])->toBe($e3['id'])->and($tbc['historial'][0]['id'])->toBe($e2['id'])->and($tbc['historial'][0]['vigencia'])->toBe('reemplazado');
    $this->postJson("{$this->base}/sanidad/estatus/{$e3['id']}/anular", ['motivo' => 'Era de otro campo'])->assertOk()->assertJsonPath('data.estado', 'anulado');
    expect($this->getJson("{$this->base}/sanidad/oficial")->json('data.enfermedades.1.estatus.id'))->toBe($e2['id'])
        ->and($this->getJson("{$this->base}/sanidad/oficial")->json('data.enfermedades.0.estatus.id'))->toBe($e1['id']);
});

it('resumen para el veterinario en CSV y PDF; permisos 403/404', function () {
    $this->postJson("{$this->base}/sanidad/protocolos", [...protocoloBrucelosis($this), 'confirmar_advertencias' => true])->assertCreated();
    $csv = $this->get("{$this->base}/sanidad/oficial/resumen-veterinario?formato=csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
    $lineas = explode("\n", trim($csv));
    expect($lineas)->toHaveCount(5)->and($lineas[0])->toStartWith("\xEF\xBB\xBFenfermedad;estatus")->and($lineas[3])->toContain('AR003', 'positivo')
        ->and($lineas[4])->toContain('AR-999;no;');

    Http::fake(['*/forms/chromium/convert/html' => Http::sequence()->push('%PDF-1.7 fake', 200)->push('error', 500)]);
    $this->get("{$this->base}/sanidad/oficial/resumen-veterinario")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Http::assertSent(fn ($req) => str_contains((string) $req->body(), 'no reemplaza SIGSA') && str_contains((string) $req->body(), 'Lab. Regional Mercedes'));
    $this->getJson("{$this->base}/sanidad/oficial/resumen-veterinario?formato=pdf")->assertStatus(503);

    $p = $this->getJson("{$this->base}/sanidad/protocolos")->json('data.0.id');
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/sanidad/oficial")->assertOk();
    $this->postJson("{$this->base}/sanidad/protocolos", protocoloBrucelosis($this))->assertForbidden();
    $this->postJson("{$this->base}/sanidad/protocolos/{$p}/anular", ['motivo' => 'xxx'])->assertForbidden();
    $this->postJson("{$this->base}/sanidad/estatus", [])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/sanidad/oficial")->assertNotFound();
    $this->getJson("{$this->base}/sanidad/protocolos/{$p}")->assertNotFound();
    $this->postJson("{$this->base}/sanidad/protocolos", [])->assertNotFound();
    $this->getJson("{$this->base}/animales/{$this->vacas[0]['animal_id']}/pruebas")->assertNotFound();
});

it('foto de protocolo → lectura asistida → revisión → confirmación registra el protocolo como transcripción revisada', function () {
    $docId = documentoSanitario($this);
    $l = fn (?string $v, float $c = 0.95) => ['valor' => $v, 'confianza' => $c, 'pagina' => 1, 'alternativas' => []];
    FakeExtractor::$salida = ['variante' => 'protocolo_laboratorio', 'problemas' => [],
        'campos' => ['enfermedad' => $l('Brucelosis'), 'prueba' => $l('BPA'), 'motivo' => $l('Precondición movimiento'), 'modalidad' => $l(null, 0.1),
            'protocolo_numero' => $l('P-77'), 'laboratorio' => $l('Lab. Regional Mercedes'), 'fecha_muestreo' => $l('20/09/26'), 'fecha_resultado' => $l('23/09/2026'),
            'renspa' => $l('18.105.0.00001/00'), 'establecimiento' => $l('La Esperanza'), 'titular' => $l('Don Ramón S.A.'), 'veterinario_nombre' => $l('Ana Ríos', 0.6),
            'veterinario_matricula' => $l('MP 1234'), 'firma_veterinario' => $l('si'), 'observaciones' => $l(null, 0.1)],
        'animales' => [
            ['identificacion' => $l('AR-001'), 'categoria' => $l('Vaca'), 'muestra' => $l('1'), 'lectura_0h_mm' => $l(null, 0.1), 'lectura_72h_mm' => $l(null, 0.1), 'resultado' => $l('Neg')],
            ['identificacion' => $l('AR-0O2', 0.5), 'categoria' => $l('Vaca'), 'muestra' => $l('2'), 'lectura_0h_mm' => $l(null, 0.1), 'lectura_72h_mm' => $l(null, 0.1), 'resultado' => $l('Pos')],
        ],
        'totales' => ['muestras' => $l('3'), 'positivos' => $l('1')]];
    $id = (string) Str::uuid7();
    $b = $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'protocolo_sanitario'])->assertStatus(202)->json('data');
    $b = $this->getJson("{$this->base}/borradores/{$id}")->assertOk()->assertJsonPath('data.estado', 'para_revisar')->assertJsonPath('data.esquema', 'protocolo_sanitario/1')
        ->assertJsonPath('data.presentacion.agregables.0.grupo', 'animales')->json('data');
    $campos = collect($b['campos'])->keyBy('ruta');
    expect($campos['prueba']['valor'])->toBe('brucelosis_bpa')->and($campos['motivo']['valor'])->toBe('movimiento')
        ->and($campos['fecha_muestreo']['valor'])->toBe('2026-09-20')->and($campos['animales.1.resultado']['valor'])->toBe('positivo')
        ->and(collect($b['advertencias'])->pluck('codigo')->all())->toBe(['animal_no_encontrado', 'marca_reactor', 'totales_no_cierran']);

    // La persona corrige la caravana mal leída, agrega una fila a mano y revisa todo.
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'animales.1.identificacion', 'estado' => 'editado', 'valor' => 'AR-002']], 'agregar_fila' => 'animales'])->assertOk();
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'animales.2.lectura_0h_mm', 'estado' => 'editado', 'valor' => '4,5']]])
        ->assertOk()->assertJsonPath('data.campos.'.(count($b['campos']) + 3).'.valor', '4.5');
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'animales.2.lectura_0h_mm', 'estado' => 'editado', 'valor' => 'x']]])->assertStatus(422);
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [
        ['ruta' => 'animales.2.identificacion', 'estado' => 'editado', 'valor' => '032000000000123'], ['ruta' => 'animales.2.resultado', 'estado' => 'editado', 'valor' => 'negativo'],
        ['ruta' => 'animales.2.lectura_0h_mm', 'estado' => 'no_aplica'],
    ]])->assertOk();
    $pend = collect($this->getJson("{$this->base}/borradores/{$id}")->json('data.campos'))->where('estado', 'pendiente')->map(fn ($c) => ['ruta' => $c['ruta'], 'estado' => 'correcto'])->values()->all();
    $adv = $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => $pend])->assertOk()->assertJsonPath('data.pendientes', 0)->json('data.advertencias');
    expect(collect($adv)->pluck('codigo')->all())->toBe(['marca_reactor']);

    $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    $this->postJson("{$this->base}/borradores/{$id}/confirmar", ['confirmar_advertencias' => true])->assertOk()->assertJsonPath('data.estado', 'confirmado');
    $p = DB::table('protocolos_sanitarios')->where('borrador_id', $id)->first();
    expect($p->origen)->toBe('transcripcion_revisada')->and($p->documento_id)->toBe($docId)->and($p->vence_el)->toBe('2026-11-19')
        ->and($p->observaciones)->toContain('Titular: Don Ramón S.A.')
        ->and(DB::table('protocolo_resultados')->where('protocolo_id', $p->id)->whereNotNull('animal_id')->count())->toBe(3)
        ->and(DB::table('animales_estado')->where('animal_id', $this->vacas[1]['animal_id'])->value('reactor'))->toBeTrue()
        ->and(DB::table('documento_vinculos')->where('documento_id', $docId)->where('entidad_tipo', 'evento')->count())->toBe(2);
    // Confirmar de nuevo es idempotente.
    $this->postJson("{$this->base}/borradores/{$id}/confirmar", ['confirmar_advertencias' => true])->assertOk();
    expect(DB::table('protocolos_sanitarios')->count())->toBe(1);
});
