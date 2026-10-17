<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use App\Domain\Documentos\Borradores\Familias\Fidha;
use App\Domain\Documentos\Borradores\Familias\LibroTratamientos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** TPA-65: Libro de Registro de Tratamientos (Res. 666/2011) y FIDHA (Res. 655/2026). */
beforeEach(function () {
    Storage::fake();
    config(['tropilla.archivos.driver' => 'local', 'tropilla.extraccion.driver' => 'fake']);
    FakeExtractor::reset();
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento(extra: ['renspa' => '18.105.0.00001/00']);
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
});

function sanLeido(?string $valor, float $conf = 0.9): array
{
    return ['valor' => $valor, 'confianza' => $conf, 'pagina' => 1, 'alternativas' => $conf < 0.6 && $valor !== null ? ['otra lectura'] : []];
}

function sanBorrador(object $t, string $familia, array $salida): array
{
    FakeExtractor::$salida = $salida;
    $sha = app(GuardarArchivo::class)->handle('foto '.Str::random(), 'image/jpeg', $t->dueno);
    $docId = $t->postJson("{$t->base}/documentos", ['tipo' => $familia, 'titulo' => 'Foto', 'archivos' => [['sha256' => $sha, 'bytes' => 10, 'mime' => 'image/jpeg']]])
        ->assertCreated()->json('data.id');

    return $t->postJson("{$t->base}/borradores", ['id' => (string) Str::uuid7(), 'documento_id' => $docId, 'familia' => $familia])->assertStatus(202)
        ->assertJsonPath('data.estado', 'para_revisar')->json('data');
}

function sanCampo(array $b, string $ruta): ?array
{
    return collect($b['campos'])->firstWhere('ruta', $ruta);
}

/** Revisión en bloque como «Todo bien»: los dudosos quedan pendientes. */
function sanTodoBien(object $t, string $id): array
{
    $pend = array_values(array_filter($t->getJson("{$t->base}/borradores/{$id}")->json('data.campos'), fn ($c) => $c['estado'] === 'pendiente'));

    return $t->patchJson("{$t->base}/borradores/{$id}/campos", ['campos' => array_map(fn ($c) => ['ruta' => $c['ruta'], 'estado' => 'correcto'], $pend)])->assertOk()->json('data');
}

function salidaLibro(array $extra = []): array
{
    $l = fn (?string $v, float $c = 0.9) => sanLeido($v, $c);
    $fila = fn (array $x) => [...array_fill_keys(['fecha_inicio', 'animales', 'caravanas', 'diagnostico', 'producto', 'principio_activo', 'dosis', 'via',
        'duracion_dias', 'responsable', 'retiro_carne_dias', 'retiro_leche_dias', 'observaciones'], $l(null)), ...$x];

    return array_replace_recursive([
        'variante' => '666_2011', 'problemas' => [],
        'campos' => ['establecimiento' => $l('La Esperanza'), 'renspa' => $l('18.105.0.00001/00'), 'titular' => $l('Don Ramón S.A.'), 'folio' => $l('12'),
            'rubrica' => $l('Oficina Local Resistencia'), 'veterinario_responsable' => $l('Dra. Ana Ruiz'), 'matricula_veterinario' => $l('MP 1234')],
        'tratamientos' => [
            $fila(['fecha_inicio' => $l('03/09/26'), 'animales' => $l('Lote Vaquillonas 2025'), 'diagnostico' => $l('Queratoconjuntivitis'),
                'producto' => $l('Oxitetraciclina LA'), 'principio_activo' => $l('oxitetraciclina'), 'dosis' => $l('1 ml/10 kg', 0.45), 'via' => $l('I.M.'),
                'duracion_dias' => $l('3'), 'responsable' => $l('Dra. Ana Ruiz'), 'retiro_carne_dias' => $l('28')]),
            $fila(['fecha_inicio' => $l('10/09/26'), 'animales' => $l('vaca A-101'), 'caravanas' => $l('A-101'), 'diagnostico' => $l('Mastitis'),
                'producto' => $l('Cefalosporina pomo'), 'via' => $l('intramamaria'), 'responsable' => $l('Juan (encargado)'), 'retiro_leche_dias' => $l('4')]),
        ],
    ], $extra);
}

function salidaFidha(array $extra = []): array
{
    $l = fn (?string $v, float $c = 0.9) => sanLeido($v, $c);

    return array_replace_recursive([
        'variante' => '655_2026', 'problemas' => [],
        'campos' => [
            'numero' => $l('F-2026-00871'), 'fecha_inspeccion' => $l('20/09/2026'), 'lugar_inspeccion' => $l('Manga del establecimiento'), 'zona_origen' => $l('Zona de control'),
            'resultado' => $l('Negativo'), 'causa_rechazo' => $l(null), 'agente_nombre' => $l('Pedro Sosa'), 'agente_identificacion' => $l('SENASA 5521'),
            'firma_agente' => $l('si'), 'renspa_origen' => $l('18.105.0.00001/00'), 'establecimiento_origen' => $l('La Esperanza'), 'titular' => $l('Don Ramón S.A.'),
            'renspa_destino' => $l('22.033.0.00456/00'), 'destino' => $l('El Recreo, Goya'), 'dte_numero' => $l('0555-00000001'), 'especie' => $l('Bovino'),
            'cantidad' => $l('8'), 'tratamiento_precaucional' => $l('si'), 'producto_comercial' => $l('Garrapaticida Fluazurón'), 'principio_activo' => $l('fluazurón'),
            'fecha_tratamiento' => $l('19/09/2026'), 'metodo' => $l('Pour on'), 'observaciones' => $l(null),
        ],
    ], $extra);
}

it('libro de tratamientos: los manuscritos dudosos no se aceptan en bloque, cada fila es un tratamiento con su sujeto y queda buscable con la foto como fuente', function () {
    $lote = crearLote($this->est, 'BOV', true, 'Vaquillonas 2025');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_VAQUILLONA' => 12]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-08-01'])->assertCreated();
    $vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'A-101']]]);
    $this->postJson("{$this->base}/eventos", altaBody([$vaca], '2026-08-01'))->assertCreated();

    $b = sanBorrador($this, 'libro_tratamientos', salidaLibro());
    expect($b['esquema'])->toBe(LibroTratamientos::ESQUEMA)
        ->and($b['presentacion']['grupos']['tratamientos'])->toBe('Tratamiento :n')
        ->and($b['presentacion']['agregables'])->toBe([['grupo' => 'tratamientos', 'etiqueta' => 'Agregar tratamiento']])
        ->and(sanCampo($b, 'tratamientos.0.fecha_inicio')['valor'])->toBe('2026-09-03')
        ->and(sanCampo($b, 'tratamientos.0.via')['valor'])->toBe('intramuscular')
        // El renglón nombra un lote que existe: TROPILLA lo sugiere (pendiente, la persona confirma).
        ->and(sanCampo($b, 'tratamientos.0.lote_id'))->toMatchArray(['valor' => $lote, 'sugerido' => true, 'estado' => 'pendiente'])
        ->and(array_column($b['advertencias'], 'codigo'))->toContain('retiro_faltante')->not->toContain('fila_sin_animales');

    // «Todo bien» en bloque: la dosis manuscrita dudosa (45 %) sigue como sugerencia pendiente.
    $r = sanTodoBien($this, $b['id']);
    expect($r['pendientes'])->toBe(1)->and(sanCampo($r, 'tratamientos.0.dosis'))->toMatchArray(['estado' => 'pendiente', 'confianza' => 0.45]);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)->assertJsonPath('code', 'revision_incompleta')
        ->assertJsonValidationErrors(['campos.tratamientos.0.dosis'], 'errors');
    // De a uno sí se confirma.
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'tratamientos.0.dosis', 'estado' => 'correcto']]])
        ->assertOk()->assertJsonPath('data.pendientes', 0);

    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    $ids = $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertOk()
        ->assertJsonCount(2, 'data.evento_ids')->json('data.evento_ids');
    $eventos = DB::table('eventos')->whereIn('id', $ids)->orderBy('ocurrido_en')->get();
    expect($eventos->pluck('tipo')->unique()->all())->toBe(['sanidad.tratamiento'])
        ->and($eventos[0]->lote_id)->toBe($lote)
        ->and(json_decode($eventos[0]->payload, true))->toEqual(['diagnostico_presuntivo' => 'Queratoconjuntivitis', 'producto_nombre' => 'Oxitetraciclina LA (oxitetraciclina)',
            'dosis_aplicada' => '1 ml/10 kg · Intramuscular', 'duracion_dias' => 3, 'veterinario' => 'Dra. Ana Ruiz', 'retiro_carne_dias' => 28])
        ->and(DB::table('evento_animales')->where('evento_id', $eventos[1]->id)->pluck('animal_id')->all())->toBe([$vaca['animal_id']])
        ->and(json_decode($eventos[1]->sobre, true)['observaciones'])->toContain('folio 12')->toContain('No reemplaza al libro rubricado');

    // Libro buscable: texto, tipo y documento fuente; separado del libro oficial.
    $libro = $this->getJson("{$this->base}/sanidad/libro-tratamientos?q=OXITETRA")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tipo', 'tratamiento')->assertJsonPath('data.0.fecha', '2026-09-03')->assertJsonPath('data.0.sujeto.lote_nombre', 'Vaquillonas 2025')
        ->assertJsonPath('data.0.retiro_carne_dias', 28)->assertJsonPath('data.0.transcripto', true)
        ->assertJsonPath('data.0.documentos.0.id', $b['documento_id'])->json();
    expect($libro['aviso'])->toContain('No reemplaza al Libro de Registro de Tratamientos rubricado');
    $this->getJson("{$this->base}/sanidad/libro-tratamientos?q=mastitis&desde=2026-09-05")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sujeto.animales', 1)->assertJsonPath('data.0.retiro_leche_dias', 4);
    $this->getJson("{$this->base}/sanidad/libro-tratamientos?tipo=inspeccion")->assertOk()->assertJsonCount(0, 'data');
});

it('libro: una fila sin animales bloquea, la fecha «no aplica» omite la fila y las caravanas desconocidas bloquean', function () {
    $b = sanBorrador($this, 'libro_tratamientos', salidaLibro(['tratamientos' => [['animales' => sanLeido('rodeo general')], ['caravanas' => sanLeido('ZZ-9')]]]));
    $codigos = array_column($b['advertencias'], 'codigo');
    expect($codigos)->toContain('fila_sin_animales', 'caravana_desconocida');
    sanTodoBien($this, $b['id']);
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'tratamientos.0.dosis', 'estado' => 'no_legible']]])->assertOk();
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    // Omitir las dos filas (fecha «no aplica») deja nada para registrar.
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'tratamientos.0.fecha_inicio', 'estado' => 'no_aplica'],
        ['ruta' => 'tratamientos.1.fecha_inicio', 'estado' => 'no_aplica']]])->assertOk()->assertJsonMissing(['codigo' => 'fila_sin_animales']);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)
        ->assertJsonPath('code', 'regla_bloqueante')->assertJsonPath('message', 'No hay tratamientos para registrar.');
});

it('FIDHA: registra la inspección y el tratamiento precaucional sobre el lote y se vincula al movimiento con el mismo DT-e', function () {
    $lote = crearLote($this->est, 'BOV', true, 'Novillos');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_NOVILLO' => 20]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-08-01'])->assertCreated();
    $egreso = $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BOV_NOVILLO' => 8]],
        'payload' => ['motivo' => 'venta', 'dte_numero' => '0555-00000001'], 'ocurrido_en' => '2026-09-21'])->assertCreated()->json('data.id');

    $b = sanBorrador($this, 'fidha', salidaFidha());
    expect($b['esquema'])->toBe(Fidha::ESQUEMA)
        ->and(sanCampo($b, 'resultado')['valor'])->toBe('negativo')->and(sanCampo($b, 'metodo')['valor'])->toBe('pour_on')
        ->and(sanCampo($b, 'tratamiento_precaucional')['valor'])->toBeTrue()
        ->and(array_column($b['advertencias'], 'codigo'))->toBe(['sin_animales']);
    sanTodoBien($this, $b['id']);
    $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->patchJson("{$this->base}/borradores/{$b['id']}/campos", ['campos' => [['ruta' => 'lote_id', 'estado' => 'editado', 'valor' => $lote]]])
        ->assertOk()->assertJsonPath('data.advertencias', []);

    $conf = $this->postJson("{$this->base}/borradores/{$b['id']}/confirmar")->assertOk()->assertJsonCount(2, 'data.evento_ids')
        ->assertJsonPath('data.eventos_vinculados', [$egreso])->json('data');
    $ev = DB::table('eventos')->whereIn('id', $conf['evento_ids'])->get()->keyBy('tipo');
    expect(json_decode($ev['sanidad.observacion_clinica']->payload, true)['signos'])->toBe(['inspección de garrapata: negativo'])
        ->and(json_decode($ev['sanidad.observacion_clinica']->payload, true)['descripcion'])->toContain('FIDHA N.º F-2026-00871')->toContain('Pedro Sosa (SENASA 5521)')
        ->and(json_decode($ev['sanidad.desparasitacion']->payload, true))->toMatchArray(['producto_nombre' => 'Garrapaticida Fluazurón', 'tipo' => 'externo', 'dosis_aplicada' => 'Pour-on / dorsal'])
        ->and(substr((string) $ev['sanidad.desparasitacion']->ocurrido_en, 0, 10))->toBe('2026-09-19');
    $doc = $this->getJson("{$this->base}/documentos/{$b['documento_id']}")->assertOk()->assertJsonPath('data.emisor_nombre', 'SENASA — FIDHA (Pedro Sosa)')->json('data');
    expect(collect($doc['vinculos'])->pluck('id')->sort()->values()->all())->toBe(collect([...$conf['evento_ids'], $egreso])->sort()->values()->all());

    $this->getJson("{$this->base}/sanidad/libro-tratamientos?tipo=inspeccion")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.inspeccion.resultado', 'negativo')->assertJsonPath('data.0.documentos.0.id', $b['documento_id']);
    $this->getJson("{$this->base}/sanidad/libro-tratamientos?q=fluazur")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tipo', 'desparasitacion');

    // Modelo anterior y resultado positivo: se advierte, no se corrige.
    $otro = sanBorrador($this, 'fidha', salidaFidha(['variante' => 'anterior', 'campos' => ['resultado' => sanLeido('Rechazada por positiva')]]));
    expect($otro['esquema'])->toBe(Fidha::ESQUEMA_ANTERIOR)->and(sanCampo($otro, 'resultado')['valor'])->toBe('rechazada')
        ->and(array_column($otro['advertencias'], 'codigo'))->toContain('formulario_anterior', 'inspeccion_positiva');
});

it('libro de tratamientos: permisos y validación', function () {
    $this->getJson("{$this->base}/sanidad/libro-tratamientos?tipo=vacunacion")->assertStatus(422);
    $this->getJson("{$this->base}/sanidad/libro-tratamientos?desde=03/09/2026")->assertStatus(422);
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/sanidad/libro-tratamientos")->assertOk()->assertJsonPath('data', []);
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/sanidad/libro-tratamientos")->assertStatus(404);
});
