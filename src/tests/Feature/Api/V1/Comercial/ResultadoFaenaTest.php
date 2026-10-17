<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    config(['tropilla.archivos.driver' => 'local', 'tropilla.extraccion.driver' => 'fake']);
    FakeExtractor::reset();
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.4 faena', 200)]);
    $this->travelTo('2026-10-12 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->vacas = array_map(fn ($n) => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'identificaciones' => [['tipo' => 'rfid', 'valor' => "03200000000010{$n}"]]]), [1, 2, 3]);
    $this->postJson("{$this->base}/eventos", altaBody($this->vacas, '2026-01-10'))->assertCreated();
    $this->ids = array_column($this->vacas, 'animal_id');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->ids[0]]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '450', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-10-01'])->assertCreated();
    $ids = $this->ids;
    sort($ids);
    $this->venta = $this->postJson("{$this->base}/operaciones", ['tipo' => 'venta', 'fecha' => '2026-10-05', 'especie' => 'BOV', 'categoria' => 'BOV_VACA', 'cantidad' => 3,
        'animal_ids' => $ids, 'trato' => ['comprador' => 'Frigorífico del Litoral', 'destino' => 'faena', 'modalidad' => 'al_gancho'],
        'liquidacion' => ['peso_bruto_kg' => '1350', 'precio_kg' => '5000', 'rendimiento_pct' => '55'], 'confirmar_advertencias' => true])->assertCreated()->json('data');
});

function romaneo(object $t, array $extra = []): array
{
    return array_replace([
        'id' => (string) Str::uuid7(), 'frigorifico' => 'Frigorífico del Litoral S.A.', 'frigorifico_cuit' => '30-71234567-8', 'faenador_numero' => '2154',
        'fecha_faena' => '2026-10-07', 'tropa_numero' => '4471', 'romaneo_numero' => 'R-889', 'dte_numero' => '18-123456-7', 'cabezas_declaradas' => 3,
        'origen' => 'declarado', 'operacion_id' => $t->venta['id'],
        'reses' => [
            ['garron' => '101', 'identificacion' => '032000000000101', 'media_a_kg' => '120', 'media_b_kg' => '121,0', 'categoria' => 'Vaca', 'tipificacion' => 'V 2'],
            ['garron' => '102', 'identificacion' => '32000000000102', 'peso_kg' => '250', 'categoria' => 'Vaca', 'tipificacion' => 'V 3'],
            ['garron' => '103', 'identificacion' => '032000000000999', 'peso_kg' => '230', 'categoria' => 'Vaca', 'decomiso' => 'Hígado'],
        ],
    ], $extra);
}

it('registra el romaneo, asocia RFID–garrón, concilia con la venta (faltantes, rinde, importe) y separa oficial de cálculo', function () {
    $body = romaneo($this);
    $r = $this->postJson("{$this->base}/faena/resultados", $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['res_sin_asociar']);

    $d = $this->postJson("{$this->base}/faena/resultados", [...$body, 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.oficial.totales.reses', 3)->assertJsonPath('data.oficial.totales.kg', '721')->assertJsonPath('data.oficial.totales.asociadas', 2)
        ->assertJsonPath('data.oficial.reses.0.peso_kg', '241')->assertJsonPath('data.oficial.reses.0.animal_id', $this->ids[0])
        ->assertJsonPath('data.oficial.reses.1.animal_id', $this->ids[1])->assertJsonPath('data.oficial.reses.2.animal_id', null)
        ->assertJsonPath('data.origen_leyenda', 'Cargado a mano por el productor')->json('data');
    $c = $d['conciliacion'];
    expect($c['cabezas'])->toBe(['enviadas' => 3, 'reses' => 3, 'conciliadas' => 2, 'faltantes' => 1, 'no_estaban_en_la_venta' => 0, 'sin_asociar' => 1])
        ->and($c['kg'])->toMatchArray(['reses' => '721', 'vivo' => '1350', 'liquidados' => null])
        ->and($c['rinde_pct'])->toBe('53.4')->and($c['importe']['esperado_al_gancho'])->toBe('3605000')
        ->and($c['animales'][0])->toMatchArray(['animal_id' => $this->ids[2], 'estado' => 'faltante', 'garron' => null])
        ->and(collect($c['animales'])->firstWhere('animal_id', $this->ids[0]))->toMatchArray(['garron' => '101', 'peso_vivo_kg' => '450', 'rinde_pct' => '53.6', 'estado' => 'conciliado'])
        ->and($c['leyenda'])->toContain('Cálculo propio de TROPILLA')->and($d['oficial']['leyenda'])->toContain('Dato del frigorífico')
        ->and($c['avisos'])->toContain('1 animal enviado no aparece en el romaneo (o su caravana no se leyó).');
    // Idempotente; las reses son append-only; no se tocan pesos vivos ni se crean eventos.
    $this->postJson("{$this->base}/faena/resultados", [...$body, 'confirmar_advertencias' => true])->assertOk();
    expect(DB::table('animales_estado')->where('animal_id', $this->ids[1])->value('peso_kg'))->toBeNull();

    // Llega la liquidación (con un kg de menos) y la factura: la conciliación lo marca.
    $factura = app(GuardarArchivo::class)->handle('factura', 'application/pdf', $this->dueno);
    $facturaId = $this->postJson("{$this->base}/documentos", ['tipo' => 'factura', 'titulo' => 'Factura A 0001-00004471',
        'archivos' => [['sha256' => $factura, 'bytes' => 7, 'mime' => 'application/pdf']]])->assertCreated()->json('data.id');
    $liq = ['numero' => 'L-5521', 'fecha' => '2026-10-09', 'kg' => '720', 'precio_kg' => '5000', 'bruto' => '3600000',
        'deducciones' => [['concepto' => 'Comisión 3 %', 'importe' => '108000'], ['concepto' => 'Sellado', 'importe' => '5000']], 'neto' => '3487000'];
    $this->patchJson("{$this->base}/faena/resultados/{$d['id']}", ['liquidacion' => $liq, 'factura_documento_id' => $facturaId, 'row_version' => 2])->assertStatus(409);
    $d2 = $this->patchJson("{$this->base}/faena/resultados/{$d['id']}", ['liquidacion' => $liq, 'factura_documento_id' => $facturaId, 'row_version' => 1])->assertOk()
        ->assertJsonPath('data.liquidacion.kg', '720')->assertJsonPath('data.factura_documento_id', $facturaId)->json('data');
    expect($d2['conciliacion']['kg']['diferencia_liquidados_vs_reses'])->toBe('-1')
        ->and($d2['conciliacion']['importe'])->toMatchArray(['diferencia_bruto_vs_esperado' => '-5000', 'deducciones' => '113000', 'liquidado_neto' => '3487000'])
        ->and($d2['conciliacion']['avisos'])->toContain('La liquidación paga 720 kg y las reses suman 721 kg.')
        ->and($d2['conciliacion']['avisos'])->toContain('Con el precio pactado, las reses valen 3.605.000 y la liquidación dice 3.600.000.');

    // Exportar: CSV (oficial + cálculo) y PDF.
    $csv = $this->get("{$this->base}/faena/resultados/{$d['id']}/exportar?formato=csv")->assertOk()->getContent();
    expect($csv)->toContain('tropilla_estado')->toContain('101;032000000000101;120;121;241;Vaca;"V 2";;032000000000101;conciliado;450;53.6')->toContain(';faltante;');
    $this->get("{$this->base}/faena/resultados/{$d['id']}/exportar")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Http::assertSent(fn ($req) => str_contains((string) $req->body(), 'Conciliación (cálculo de TROPILLA)') && str_contains((string) $req->body(), 'L-5521'));

    $this->getJson("{$this->base}/faena/resultados?operacion_id={$this->venta['id']}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.oficial.tropa_numero', '4471');
    $this->postJson("{$this->base}/faena/resultados/{$d['id']}/anular", ['motivo' => 'Era de otra tropa'])->assertOk()->assertJsonPath('data.estado', 'anulado');
    $this->getJson("{$this->base}/faena/resultados")->assertJsonCount(0, 'data');
    // Las reses son append-only (al final: el error aborta la transacción del test).
    expect(fn () => DB::table('faena_reses')->where('resultado_id', $d['id'])->update(['peso_kg' => 1]))->toThrow(Exception::class);
});

it('validación, advertencias de pesos y venta, y permisos (precios privados)', function () {
    $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['reses' => [['garron' => '1', 'peso_kg' => '200'], ['garron' => '1', 'peso_kg' => '210']]]))
        ->assertStatus(422)->assertJsonValidationErrors(['reses.1.garron']);
    $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['reses' => [['garron' => '1', 'media_a_kg' => '100']]]))
        ->assertStatus(422)->assertJsonValidationErrors(['reses.0.peso_kg']);
    $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['origen' => 'importado']))->assertStatus(422)->assertJsonValidationErrors(['documento_id']);
    $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['frigorifico' => '', 'reses' => [], 'origen' => 'otro']))->assertStatus(422)
        ->assertJsonValidationErrors(['frigorifico', 'reses', 'origen']);
    $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['operacion_id' => (string) Str::uuid7()]))->assertStatus(422)->assertJsonValidationErrors(['operacion_id']);

    $r = $this->postJson("{$this->base}/faena/resultados", romaneo($this, ['fecha_faena' => '2026-10-01', 'cabezas_declaradas' => 4, 'reses' => [
        ['garron' => '7', 'identificacion' => '032000000000101', 'media_a_kg' => '120', 'media_b_kg' => '120', 'peso_kg' => '250'],
        ['garron' => '8', 'peso_kg' => '45'],
    ]]))->assertStatus(422);
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['cabezas_distintas', 'medias_no_suman', 'peso_atipico', 'faena_antes_de_la_venta']);

    $ok = romaneo($this);
    foreach (['encargado', 'asesor'] as $rol) {
        Sanctum::actingAs(miembro($this->est, $rol));
        $this->postJson("{$this->base}/faena/resultados", $ok)->assertForbidden();
        $this->getJson("{$this->base}/faena/resultados")->assertForbidden();
    }
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/faena/resultados")->assertNotFound();
    $this->postJson("{$this->base}/faena/resultados", [])->assertNotFound();
});

it('lee la planilla digital del frigorífico (CSV con coma decimal y encabezados propios) sin guardar nada', function () {
    $csv = "Garrón;Caravana electrónica;Media A;Media B;Categoría;Tipificación\n101;032000000000101;120,5;121;Vaca;V 2\n102;;118;119,5;Vaca;V 3\n;;x;;;\n";
    $r = $this->post("{$this->base}/faena/planilla", ['archivo' => UploadedFile::fake()->createWithContent('romaneo.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect($r['reses'][0])->toMatchArray(['garron' => '101', 'identificacion' => '032000000000101', 'media_a_kg' => '120.5', 'media_b_kg' => '121', 'peso_kg' => '241.5', 'tipificacion' => 'V 2'])
        ->and($r['reses'][1]['peso_kg'])->toBe('237.5')->and($r['columnas'])->toContain('garron', 'identificacion', 'media_a_kg')
        ->and(collect($r['errores'])->pluck('fila')->all())->toBe([4, 4, 4])
        ->and(DB::table('resultados_faena')->count())->toBe(0);
    $this->post("{$this->base}/faena/planilla", ['archivo' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF')], ['Accept' => 'application/json'])->assertStatus(422);
});

it('romaneo en PDF/foto → lectura asistida → revisión → confirmación registra el resultado como transcripción revisada', function () {
    $sha = app(GuardarArchivo::class)->handle('%PDF-1.4 romaneo', 'application/pdf', $this->dueno);
    $docId = $this->postJson("{$this->base}/documentos", ['tipo' => 'resultado_faena', 'titulo' => 'Romaneo tropa 4471',
        'archivos' => [['sha256' => $sha, 'bytes' => 16, 'mime' => 'application/pdf']]])->assertCreated()->assertJsonPath('data.privacidad', 'comercial')->json('data.id');
    $l = fn (?string $v, float $c = 0.95) => ['valor' => $v, 'confianza' => $c, 'pagina' => 1, 'alternativas' => []];
    FakeExtractor::$salida = ['variante' => 'romaneo', 'problemas' => [],
        'campos' => ['frigorifico' => $l('Frigorífico del Litoral S.A.'), 'frigorifico_cuit' => $l('30-71234567-8'), 'faenador_numero' => $l('2154'),
            'fecha_faena' => $l('07/10/2026'), 'tropa_numero' => $l('4471'), 'romaneo_numero' => $l('R-889'), 'dte_numero' => $l(null, 0.1), 'cabezas' => $l('2'),
            'tipificador' => $l('J. Gómez'), 'total_kg' => $l('491,5')],
        'reses' => [
            ['garron' => $l('101'), 'identificacion' => $l('032000000000101'), 'media_a_kg' => $l('120,5'), 'media_b_kg' => $l('121'), 'peso_kg' => $l(null, 0.1), 'categoria' => $l('Vaca'), 'tipificacion' => $l('V2'), 'decomiso' => $l(null, 0.1)],
            ['garron' => $l('102'), 'identificacion' => $l('032000000000102'), 'media_a_kg' => $l(null, 0.1), 'media_b_kg' => $l(null, 0.1), 'peso_kg' => $l('25O', 0.4), 'categoria' => $l('Vaca'), 'tipificacion' => $l('V3'), 'decomiso' => $l(null, 0.1)],
        ]];
    $id = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'resultado_faena'])->assertStatus(202);
    $b = $this->getJson("{$this->base}/borradores/{$id}")->assertOk()->assertJsonPath('data.esquema', 'resultado_faena.romaneo/1')
        ->assertJsonPath('data.presentacion.agregables.0.etiqueta', 'Agregar res')->json('data');
    $campos = collect($b['campos'])->keyBy('ruta');
    expect($campos['fecha_faena']['valor'])->toBe('2026-10-07')->and($campos['reses.0.media_a_kg']['valor'])->toBe('120.5')
        ->and($campos['reses.1.peso_kg']['valor'])->toBeNull()->and($campos['reses.1.peso_kg']['confianza'])->toBe(0.3)
        ->and(collect($b['advertencias'])->pluck('codigo')->all())->toContain('peso_faltante');

    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'reses.1.peso_kg', 'estado' => 'editado', 'valor' => '250']]])->assertOk();
    $pend = collect($this->getJson("{$this->base}/borradores/{$id}")->json('data.campos'))->where('estado', 'pendiente')->map(fn ($c) => ['ruta' => $c['ruta'], 'estado' => 'correcto'])->values()->all();
    $adv = $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => $pend])->assertOk()->json('data.advertencias');
    expect(collect($adv)->pluck('codigo')->all())->toBe([]);
    $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertOk()->assertJsonPath('data.estado', 'confirmado');
    $r = $this->getJson("{$this->base}/faena/resultados?borrador_id={$id}")->assertOk()->json('data.0');
    expect($r['origen'])->toBe('transcripcion_revisada')->and($r['documento_id'])->toBe($docId)->and($r['oficial']['totales'])->toBe(['reses' => 2, 'kg' => '491.5', 'asociadas' => 2]);
    // Vincular la venta después.
    $this->patchJson("{$this->base}/faena/resultados/{$r['id']}", ['operacion_id' => $this->venta['id'], 'row_version' => 1])->assertOk()
        ->assertJsonPath('data.conciliacion.cabezas.faltantes', 1)->assertJsonPath('data.conciliacion.venta.contraparte', 'Frigorífico del Litoral');
});
