<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use App\Domain\Documentos\Borradores\Familias\Dte;
use App\Domain\Documentos\Borradores\Familias\GuiaProvincial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** TPA-64: DT-e y guía provincial (DUT Chaco) → conciliación y movimiento de hacienda. */
beforeEach(function () {
    Storage::fake();
    config(['tropilla.archivos.driver' => 'local', 'tropilla.extraccion.driver' => 'fake']);
    FakeExtractor::reset();
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento(extra: ['renspa' => '18.105.0.00001/00']);
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
});

function movLeido(?string $valor, float $conf = 0.95): array
{
    return ['valor' => $valor, 'confianza' => $conf, 'pagina' => 1, 'alternativas' => []];
}

/** Documento capturado con una foto (como lo manda la app). */
function movDocumento(object $t, string $tipo): string
{
    $sha = app(GuardarArchivo::class)->handle('foto '.Str::random(), 'image/jpeg', $t->dueno);

    return $t->postJson("{$t->base}/documentos", ['tipo' => $tipo, 'titulo' => 'Foto', 'archivos' => [['sha256' => $sha, 'bytes' => 10, 'mime' => 'image/jpeg']]])
        ->assertCreated()->json('data.id');
}

/** Constancia de DT-e: sale de este campo (RENSPA propio) a invernada, 6 vacas + 4 vaquillonas, total 11 (no cierra). */
function salidaDte(array $extra = []): array
{
    $l = fn (?string $v) => movLeido($v);

    return array_replace_recursive([
        'variante' => 'dte_356_2008', 'problemas' => [],
        'campos' => [
            'numero' => $l('0123-45678901'), 'codigo_verificacion' => $l('AB12CD'), 'fecha_emision' => $l('14/09/26'), 'vigencia_hasta' => $l('16/09/2026'),
            'estado_documento' => $l('Emitido'), 'motivo' => $l('Invernada'), 'renspa_origen' => $l('18.105.0.00001/00'), 'titular_origen' => $l('Don Ramón S.A.'),
            'cuit_origen' => $l('30-71234567-8'), 'establecimiento_origen' => $l('La Esperanza'), 'localidad_origen' => $l('Gral. San Martín, Chaco'),
            'renspa_destino' => $l('22.033.0.00456/00'), 'titular_destino' => $l('Invernadas del Norte SRL'), 'cuit_destino' => $l(null),
            'establecimiento_destino' => $l('El Recreo'), 'localidad_destino' => $l('Goya, Corrientes'), 'transportista' => $l('Transportes Pérez'),
            'cuit_transportista' => $l(null), 'patentes' => $l('AB123CD / AC456EF'), 'precintos' => $l(null), 'requisitos_sanitarios' => $l('Aftosa vigente'),
            'fidha_numero' => $l(null), 'observaciones' => $l(null), 'total_cabezas' => $l('11'),
        ],
        'detalle' => [
            ['especie' => $l('Bovino'), 'categoria' => $l('Vacas'), 'cantidad' => $l('6'), 'marca' => $l('R/E')],
            ['especie' => $l('Bovino'), 'categoria' => $l('Vaquillonas'), 'cantidad' => $l('4', 0.5), 'marca' => $l('R/E')],
        ],
    ], $extra);
}

function salidaGuia(array $extra = []): array
{
    $base = salidaDte();
    unset($base['campos']['codigo_verificacion'], $base['campos']['estado_documento'], $base['campos']['precintos'], $base['campos']['requisitos_sanitarios'], $base['campos']['fidha_numero']);

    return array_replace_recursive($base, ['variante' => 'dut_chaco', 'campos' => [
        'numero' => movLeido('DUT-778899'), 'provincia' => movLeido('Chaco'), 'dte_numero' => movLeido('0123 45678901'), 'boleto_marca' => movLeido('B-1450'),
        'intervencion_despacho' => movLeido('Comisaría Gral. San Martín'), 'intervencion_destino' => movLeido(null), 'guia_tributaria' => movLeido('T-5521'),
    ]], $extra);
}

/** Pide la lectura y devuelve el borrador. */
function movBorrador(object $t, string $familia, string $tipoDoc, array $salida): array
{
    FakeExtractor::$salida = $salida;
    $docId = movDocumento($t, $tipoDoc);

    return $t->postJson("{$t->base}/borradores", ['id' => (string) Str::uuid7(), 'documento_id' => $docId, 'familia' => $familia])->assertStatus(202)
        ->assertJsonPath('data.estado', 'para_revisar')->json('data');
}

/** Decide campos puntuales (de a uno) y marca el resto de los pendientes como correctos. */
function movRevisar(object $t, string $id, array $decisiones = []): array
{
    foreach ($decisiones as $ruta => $d) {
        $t->patchJson("{$t->base}/borradores/{$id}/campos", ['campos' => [['ruta' => $ruta, ...$d]]])->assertOk();
    }
    $campos = $t->getJson("{$t->base}/borradores/{$id}")->json('data.campos');
    foreach (array_filter($campos, fn ($c) => $c['estado'] === 'pendiente') as $c) {
        $t->patchJson("{$t->base}/borradores/{$id}/campos", ['campos' => [['ruta' => $c['ruta'], 'estado' => 'correcto']]])->assertOk();
    }

    return $t->getJson("{$t->base}/borradores/{$id}")->assertJsonPath('data.pendientes', 0)->json('data');
}

function campoDe(array $b, string $ruta): ?array
{
    return collect($b['campos'])->firstWhere('ruta', $ruta);
}

it('DT-e de venta: sugiere egreso, concilia la suma, registra el egreso del lote con el DT-e y versiona el documento como fuente', function () {
    $lote = crearLote($this->est, 'BOV', true, 'Vientres');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_VACA' => 10, 'BOV_VAQUILLONA' => 5]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-08-01'])->assertCreated();

    $b = movBorrador($this, 'dte', 'dte_copia', salidaDte());
    expect($b['esquema'])->toBe(Dte::ESQUEMA)
        ->and($b['presentacion']['esquemas'][Dte::ESQUEMA])->toBe('DT-e (Res. SENASA 356/2008)')
        ->and($b['presentacion']['grupos']['movimiento'])->toBe('Movimiento en TROPILLA')
        ->and($b['aviso'])->toContain('No es una presentación oficial')
        ->and(campoDe($b, 'fecha_emision'))->toMatchArray(['valor' => '2026-09-14', 'critico' => true])
        ->and(campoDe($b, 'motivo')['valor'])->toBe('invernada')
        ->and(campoDe($b, 'detalle.1.categoria_codigo'))->toMatchArray(['valor' => 'BOV_VAQUILLONA', 'sugerido' => true])
        // Sugerencias de TROPILLA (pendientes: la persona decide): sale de este campo y no hay movimiento con ese DT-e.
        ->and(campoDe($b, 'movimiento_sentido'))->toMatchArray(['valor' => 'egreso', 'sugerido' => true, 'estado' => 'pendiente'])
        ->and(campoDe($b, 'movimiento_registro'))->toMatchArray(['valor' => 'registrar', 'sugerido' => true])
        ->and(array_column(campoDe($b, 'movimiento_lote_id')['opciones'], 'valor'))->toContain($lote)
        ->and(array_column($b['advertencias'], 'codigo'))->toContain('totales_no_cierran', 'lote_sin_elegir')
        ->and(DB::table('eventos')->where('tipo', 'hacienda.egreso')->count())->toBe(0);

    // Sin lote no se registra (bloqueante); con lote, la suma que no cierra pide confirmación explícita.
    movRevisar($this, $b['id']);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'movimiento_lote_id', 'estado' => 'editado', 'valor' => $lote]]])
        ->assertOk()->assertJsonMissing(['codigo' => 'lote_sin_elegir']);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonFragment(['codigo' => 'totales_no_cierran']);
    // Corregir el total (el papel decía 10, se leyó 11) deja todo conciliado.
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'total_cabezas', 'estado' => 'editado', 'valor' => 10]]])
        ->assertOk()->assertJsonPath('data.advertencias', []);

    $conf = $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertOk()->assertJsonPath('data.estado', 'confirmado')
        ->assertJsonCount(1, 'data.evento_ids')->assertJsonPath('data.eventos_vinculados', [])->json('data');
    $ev = DB::table('eventos')->where('id', $conf['evento_ids'][0])->first();
    expect($ev->tipo)->toBe('hacienda.egreso')
        ->and(json_decode($ev->conteo, true))->toBe(['BOV_VACA' => 6, 'BOV_VAQUILLONA' => 4])
        ->and(json_decode($ev->payload, true))->toMatchArray(['motivo' => 'venta', 'destino' => 'invernada', 'dte_numero' => '0123-45678901',
            'comprador' => 'Invernadas del Norte SRL', 'renspa_destino_declarado' => '22.033.0.00456/00', 'documento_ids' => [$b['documento_id']]])
        ->and(substr((string) $ev->ocurrido_en, 0, 10))->toBe('2026-09-14')
        ->and(DB::table('lote_conteos')->where('lote_id', $lote)->pluck('cabezas', 'categoria_codigo')->all())->toBe(['BOV_VACA' => 4, 'BOV_VAQUILLONA' => 1]);
    $doc = $this->getJson("{$this->base}/documentos/{$b['documento_id']}")->assertOk()->assertJsonPath('data.version_vigente', 2)
        ->assertJsonPath('data.emitido_en', '2026-09-14')->assertJsonPath('data.emisor_nombre', 'SENASA (SIGSA)')->json('data');
    expect(collect($doc['vinculos'])->where('tipo', 'evento')->pluck('id')->all())->toBe([$ev->id]);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('guía DUT de Chaco: se vincula al movimiento ya cargado con el mismo DT-e, concilia cabezas y no crea eventos', function () {
    $lote = crearLote($this->est, 'BOV', true, 'Vientres');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_VACA' => 10, 'BOV_VAQUILLONA' => 5]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-08-01'])->assertCreated();
    $egreso = $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_VACA' => 6, 'BOV_VAQUILLONA' => 3]],
        'payload' => ['motivo' => 'venta', 'dte_numero' => '0123-45678901'], 'ocurrido_en' => '2026-09-14'])->assertCreated()->json('data.id');

    $b = movBorrador($this, 'guia_provincial', 'guia_provincial', salidaGuia());
    expect($b['esquema'])->toBe(GuiaProvincial::ESQUEMA_DUT_CHACO)
        ->and(campoDe($b, 'dte_numero'))->toMatchArray(['valor' => '0123 45678901', 'critico' => true])
        // El N.º de DT-e de la guía coincide (sin importar el formato) con un egreso cargado: sugiere vincularlo.
        ->and(campoDe($b, 'movimiento_registro'))->toMatchArray(['valor' => 'vincular', 'sugerido' => true])
        ->and(campoDe($b, 'movimiento_evento_id')['valor'])->toBe($egreso)
        ->and(campoDe($b, 'movimiento_evento_id')['opciones'][0]['etiqueta'])->toBe('Egreso · 14/09/2026 · 9 cab. · DT-e 0123-45678901');
    $adv = collect($b['advertencias'])->keyBy('codigo');
    expect($adv->keys()->all())->toContain('cantidades_no_concilian', 'totales_no_cierran')
        ->and($adv['cantidades_no_concilian']['detalle'])->toMatchArray(['total_documento' => 11, 'total_tropilla' => 9]);

    movRevisar($this, $b['id']);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonFragment(['codigo' => 'cantidades_no_concilian']);
    $eventosAntes = DB::table('eventos')->count();
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertOk()
        ->assertJsonPath('data.evento_ids', [])->assertJsonPath('data.eventos_vinculados', [$egreso]);
    expect(DB::table('eventos')->count())->toBe($eventosAntes);
    $doc = $this->getJson("{$this->base}/documentos/{$b['documento_id']}")->assertOk()->assertJsonPath('data.emisor_nombre', 'Guía provincial — Chaco')->json('data');
    expect(collect($doc['vinculos'])->where('tipo', 'evento')->pluck('id')->all())->toBe([$egreso]);

    // Guía de otra provincia: se lee con el esquema genérico y se advierte que el modelo no está verificado.
    $otra = movBorrador($this, 'guia_provincial', 'guia_provincial', salidaGuia(['variante' => 'otra_provincia']));
    expect($otra['esquema'])->toBe(GuiaProvincial::ESQUEMA_GENERICA)
        ->and(array_column($otra['advertencias'], 'codigo'))->toContain('variante_no_verificada');
});

it('egreso por caravanas e ingreso por conteo; caravanas desconocidas y lotes que no corresponden bloquean', function () {
    $animales = [
        animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'A-101']]]),
        animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'A-102']]]),
    ];
    $this->postJson("{$this->base}/eventos", altaBody($animales, '2026-08-01'))->assertCreated();
    $salida = salidaDte(['campos' => ['total_cabezas' => movLeido('2')], 'detalle' => [['cantidad' => movLeido('2')]]]);
    unset($salida['detalle'][1]);

    $b = movBorrador($this, 'dte', 'dte_copia', $salida);
    movRevisar($this, $b['id'], ['movimiento_caravanas' => ['estado' => 'editado', 'valor' => 'A101, A-102, ZZ9']]);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)
        ->assertJsonPath('code', 'regla_bloqueante')->assertJsonPath('message', 'Caravanas: no hay animales activos con ZZ9.');
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'movimiento_caravanas', 'estado' => 'editado', 'valor' => 'A101 / a-102']]])
        ->assertOk()->assertJsonPath('data.advertencias', []);
    $id = $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertOk()->json('data.evento_ids.0');
    expect(DB::table('evento_animales')->where('evento_id', $id)->count())->toBe(2)
        ->and(DB::table('animales_estado')->whereIn('animal_id', array_column($animales, 'animal_id'))->pluck('estado')->unique()->all())->not->toContain('activo');

    // Ingreso: entra a este campo; un lote con animales individualizados o de otra especie no sirve para el conteo.
    $ingreso = salidaDte(['campos' => ['numero' => movLeido('0999-00000001'), 'renspa_origen' => movLeido('22.033.0.00456/00'), 'renspa_destino' => movLeido('18.105.0.00001/00'), 'total_cabezas' => movLeido('10')]]);
    $b2 = movBorrador($this, 'dte', 'dte_copia', $ingreso);
    expect(campoDe($b2, 'movimiento_sentido')['valor'])->toBe('ingreso')->and(campoDe($b2, 'movimiento_registro')['valor'])->toBe('registrar');
    $individual = crearLote($this->est, 'BOV', false, 'Individuales');
    $bufalos = crearLote($this->est, 'BUB', true, 'Búfalos');
    $porConteo = crearLote($this->est, 'BOV', true, 'Recría');
    $b2 = movRevisar($this, $b2['id'], ['movimiento_lote_id' => ['estado' => 'editado', 'valor' => $individual]]);
    expect(array_column($b2['advertencias'], 'codigo'))->toContain('lote_no_anonimo');
    $b2 = $this->patchJson("{$this->base}/borradores/{$b2['id']}/campos", ['campos' => [['ruta' => 'movimiento_lote_id', 'estado' => 'editado', 'valor' => $bufalos]]])->json('data');
    expect(array_column($b2['advertencias'], 'codigo'))->toContain('lote_otra_especie');
    $this->postJson("{$this->base}/borradores/{$b2['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->patchJson("{$this->base}/borradores/{$b2['id']}/campos", ['campos' => [['ruta' => 'movimiento_lote_id', 'estado' => 'editado', 'valor' => $porConteo]]])
        ->assertOk()->assertJsonPath('data.advertencias', []);
    $ev = DB::table('eventos')->where('id', $this->postJson("{$this->base}/borradores/{$b2['id']}/confirmar")->assertOk()->json('data.evento_ids.0'))->first();
    expect($ev->tipo)->toBe('hacienda.ingreso')
        ->and(json_decode($ev->payload, true))->toMatchArray(['motivo' => 'compra', 'dte_numero' => '0999-00000001',
            'origen' => ['vendedor' => 'Don Ramón S.A.', 'establecimiento' => 'La Esperanza', 'renspa_declarado' => '22.033.0.00456/00', 'localidad' => 'Gral. San Martín, Chaco']])
        ->and(DB::table('lote_conteos')->where('lote_id', $porConteo)->pluck('cabezas', 'categoria_codigo')->all())->toBe(['BOV_VACA' => 6, 'BOV_VAQUILLONA' => 4]);
});

it('permisos, pertenencia y validación del DT-e', function () {
    $b = movBorrador($this, 'dte', 'dte_copia', salidaDte());
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'movimiento_lote_id', 'estado' => 'editado', 'valor' => 'no-es-un-id']]])
        ->assertStatus(422)->assertJsonPath('code', 'validacion');
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [], 'agregar_fila' => 'vacunas'])->assertStatus(422);
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [], 'agregar_fila' => 'detalle'])->assertOk()
        ->assertJsonFragment(['ruta' => 'detalle.2.cantidad']);

    // Un movimiento de otro establecimiento no se puede vincular.
    $otro = crearEstablecimiento();
    $loteAjeno = crearLote($otro, 'BOV', true, 'Ajeno');
    Sanctum::actingAs(duenoDe($otro));
    $ajeno = $this->postJson("/api/v1/establecimientos/{$otro->id}/eventos", ['tipo' => 'hacienda.ingreso', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $loteAjeno, 'conteo' => ['BOV_VACA' => 1]],
        'payload' => ['motivo' => 'compra'], 'ocurrido_en' => '2026-09-01'])->assertCreated()->json('data.id');
    Sanctum::actingAs($this->dueno);
    $r = $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'movimiento_registro', 'estado' => 'editado', 'valor' => 'vincular'],
        ['ruta' => 'movimiento_evento_id', 'estado' => 'editado', 'valor' => $ajeno]]])->assertOk()->json('data');
    expect(array_column($r['advertencias'], 'codigo'))->toContain('movimiento_inexistente')
        ->and(array_column(campoDe($r, 'movimiento_evento_id')['opciones'], 'valor'))->not->toContain($ajeno);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/borradores/{$b['id']}")->assertOk();
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertStatus(403);
});
