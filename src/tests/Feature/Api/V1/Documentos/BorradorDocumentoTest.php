<?php

use Anthropic\Client;
use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Borradores\Extraccion\ClaudeExtractor;
use App\Domain\Documentos\Borradores\Extraccion\ExtraccionFallida;
use App\Domain\Documentos\Borradores\Extraccion\Extractor;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use App\Domain\Documentos\Borradores\Extraccion\GeminiExtractor;
use App\Domain\Documentos\Borradores\ExtraerBorradorJob;
use App\Domain\Documentos\Borradores\Familias\ActaVacunacion;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
});

/** JPEG real (la página fotografiada). */
function paginaJpeg(string $semilla): string
{
    $img = imagecreatetruecolor(200, 60);
    imagestring($img, 2, 2, 2, md5($semilla), imagecolorallocate($img, 255, 255, 255));
    ob_start();
    imagejpeg($img);

    return (string) ob_get_clean();
}

/** Campo leído por el modelo. */
function leido(?string $valor, float $conf = 0.95, ?int $pagina = 1, array $alt = []): array
{
    return ['valor' => $valor, 'confianza' => $conf, 'pagina' => $pagina, 'alternativas' => $alt];
}

/** Salida estructurada de un acta 711/2025 de 3 vacas + 2 terneras (aftosa a todas, brucelosis a las terneras). */
function salidaActa(array $extra = []): array
{
    return array_replace_recursive([
        'variante' => '711_2025', 'problemas' => [],
        'campos' => [
            'numero_acta' => leido('A-0012'), 'tipo_acto' => leido('Sistemática'), 'alcance' => leido('Total'), 'campana' => leido('1.ª 2026'),
            'fecha' => leido('12/05/26', 0.9), 'renspa' => leido('18.105.0.00001/00'), 'establecimiento' => leido('La Esperanza'), 'titular' => leido('Don Ramón S.A.'),
            'cuit_titular' => leido(null, 0.2), 'ente_sanitario' => leido('FUCOSA'), 'vacunador_nombre' => leido('Juan Pérez', 0.6), 'vacunador_matricula' => leido('V-345'),
            'responsable_nombre' => leido('Ramón Gómez'), 'responsable_dni' => leido('20.123.456'), 'firma_responsable' => leido('si'), 'firma_vacunador' => leido('si'),
            'observaciones' => leido(null, 0.1),
        ],
        'vacunas' => [
            ['enfermedad' => leido('Aftosa'), 'producto' => leido('Bioaftogen'), 'serie' => leido('L-881'), 'vencimiento' => leido('31/12/2026')],
            ['enfermedad' => leido('Brucelosis'), 'producto' => leido('Cepa 19'), 'serie' => leido('B-22'), 'vencimiento' => leido(null, 0.1)],
        ],
        'categorias' => [
            ['especie' => leido('Bovino'), 'categoria' => leido('Vacas'), 'existentes' => leido('3'), 'vacunados_aftosa' => leido('3'), 'vacunados_brucelosis' => leido(null, 0.1)],
            ['especie' => leido('Bovino'), 'categoria' => leido('Terneras'), 'existentes' => leido('2'), 'vacunados_aftosa' => leido('2'), 'vacunados_brucelosis' => leido('2', 0.55, 1, ['7'])],
        ],
        'totales' => ['existentes' => leido('5'), 'vacunados' => leido('5')],
    ], $extra);
}

/** Documento capturado (como lo manda la app): páginas por hash; `$pendientes` todavía no subieron. */
function documentoCapturado(object $t, array $completas, array $pendientes = []): array
{
    $archivos = [];
    foreach ($completas as $bytes) {
        $sha = app(GuardarArchivo::class)->handle($bytes, 'image/jpeg', $t->dueno);
        $archivos[] = ['sha256' => $sha, 'bytes' => strlen($bytes), 'mime' => 'image/jpeg'];
    }
    foreach ($pendientes as $bytes) {
        $archivos[] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'mime' => 'image/jpeg'];
    }
    $id = $t->postJson("{$t->base}/documentos", ['tipo' => 'vacunacion_aftosa', 'titulo' => 'Acta de vacunación', 'archivos' => $archivos])->assertCreated()->json('data.id');

    return [$id, array_column($archivos, 'sha256')];
}

function rodeo(object $t): void
{
    $animales = [
        ...array_map(fn () => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']), range(1, 3)),
        ...array_map(fn () => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_TERNERA']), range(1, 2)),
    ];
    $t->postJson("{$t->base}/eventos", altaBody($animales, '2026-01-10'))->assertCreated();
}

/** Marca todos los campos pendientes como correctos: en bloque, y los dudosos (que el bloque no acepta, TPA-65) de a uno. */
function revisarTodo(object $t, string $id): void
{
    $pendientes = fn () => array_values(array_filter($t->getJson("{$t->base}/borradores/{$id}")->json('data.campos'), fn ($c) => $c['estado'] === 'pendiente'));
    $t->patchJson("{$t->base}/borradores/{$id}/campos", ['campos' => array_map(fn ($c) => ['ruta' => $c['ruta'], 'estado' => 'correcto'], $pendientes())])->assertOk();
    foreach ($pendientes() as $c) {
        $t->patchJson("{$t->base}/borradores/{$id}/campos", ['campos' => [['ruta' => $c['ruta'], 'estado' => 'correcto']]])->assertOk();
    }
    $t->getJson("{$t->base}/borradores/{$id}")->assertJsonPath('data.pendientes', 0);
}

it('foto offline → borrador idempotente → lectura al completar la subida → revisión campo a campo → confirmación crea las vacunaciones y versiona el documento', function () {
    rodeo($this);
    $p2 = paginaJpeg('pagina 2');
    [$docId, $shas] = documentoCapturado($this, [paginaJpeg('pagina 1')], [$p2]);
    FakeExtractor::$salida = salidaActa();
    $id = (string) Str::uuid7();

    // La app manda el borrador con su UUID: falta una página → espera, sin leer nada.
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'esperando_archivos')->assertJsonPath('data.paginas.1.estado', 'pendiente')->assertJsonPath('data.campos', []);
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertOk()->assertJsonPath('data.id', $id);
    expect(FakeExtractor::$llamadas)->toBe([]);

    // Sube la segunda página por la subida diferida (el servidor verifica el SHA-256) → se lee sola.
    $s = $this->postJson('/api/v1/archivos/subidas', ['sha256' => $shas[1], 'bytes' => strlen($p2), 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])->json('data');
    $this->call('PUT', $s['url'], [], [], [], ['CONTENT_TYPE' => 'image/jpeg'], $p2)->assertOk();
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar", ['partes' => []])->assertOk();
    expect(FakeExtractor::$llamadas)->toHaveCount(1)->and(FakeExtractor::$llamadas[0]['paginas'])->toBe(2);

    $b = $this->getJson("{$this->base}/borradores/{$id}")->assertOk()
        ->assertJsonPath('data.estado', 'para_revisar')->assertJsonPath('data.esquema', ActaVacunacion::ESQUEMA_711)
        ->assertJsonPath('data.extraccion.version', 1)->assertJsonPath('data.extraccion.proveedor', 'fake')->assertJsonPath('data.advertencias', [])
        ->assertJsonPath('data.paginas.1.estado', 'completo')->json('data');
    $campo = fn (string $ruta) => collect($b['campos'])->firstWhere('ruta', $ruta);
    expect($campo('fecha'))->toMatchArray(['valor' => '2026-05-12', 'valor_extraido' => '12/05/26', 'estado' => 'pendiente', 'critico' => true])
        ->and($campo('alcance')['valor'])->toBe('total')->and($campo('firma_responsable')['valor'])->toBeTrue()
        ->and($campo('categorias.1.categoria_codigo'))->toMatchArray(['valor' => 'BOV_TERNERA', 'sugerido' => true])
        ->and($campo('categorias.1.vacunados_brucelosis'))->toMatchArray(['valor' => 2, 'confianza' => 0.55, 'alternativas' => ['7']])
        ->and($b['pendientes'])->toBe(count($b['campos']))
        ->and(DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->count())->toBe(0);

    // Sin revisión no se confirma nada.
    $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertStatus(422)->assertJsonPath('code', 'revision_incompleta');
    // Edición validada por tipo; cada decisión queda auditada.
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'fecha', 'estado' => 'editado', 'valor' => '12/05/2026']]])
        ->assertStatus(422)->assertJsonPath('code', 'validacion');
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [
        ['ruta' => 'vacunador_nombre', 'estado' => 'editado', 'valor' => 'Juan Pereyra'], ['ruta' => 'cuit_titular', 'estado' => 'no_legible'],
    ]])->assertOk()->assertJsonFragment(['ruta' => 'vacunador_nombre', 'valor' => 'Juan Pereyra', 'estado' => 'editado']);
    revisarTodo($this, $id);

    $conf = $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertOk()->assertJsonPath('data.estado', 'confirmado')
        ->assertJsonCount(2, 'data.evento_ids')->assertJsonPath('data.historial.correcciones.0.ruta', 'vacunador_nombre')
        ->assertJsonPath('data.historial.correcciones.0.valor_anterior', 'Juan Pérez')->json('data');
    $eventos = DB::table('eventos')->whereIn('id', $conf['evento_ids'])->orderBy('registrado_en')->get();
    $aftosa = $eventos->first(fn ($e) => json_decode($e->payload)->enfermedad === 'aftosa');
    $bruce = $eventos->first(fn ($e) => json_decode($e->payload)->enfermedad === 'brucelosis');
    expect(json_decode($aftosa->payload, true))->toMatchArray(['producto_nombre' => 'Bioaftogen', 'envase_lote' => 'L-881', 'envase_vence' => '2026-12-31',
        'vacunador' => 'Juan Pereyra (Mat. V-345)', 'campana' => '1.ª 2026'])
        ->and(DB::table('evento_animales')->where('evento_id', $aftosa->id)->count())->toBe(5)
        ->and(DB::table('evento_animales')->where('evento_id', $bruce->id)->count())->toBe(2)
        ->and(json_decode($bruce->payload, true)['numero_dosis'])->toBe('unica')
        ->and(substr((string) $aftosa->ocurrido_en, 0, 10))->toBe('2026-05-12')
        ->and($aftosa->adjuntos)->toContain($shas[0])->toContain($shas[1]);

    // El documento queda versionado con los vínculos a los eventos y la fecha leída; la imagen fuente se conserva.
    $doc = $this->getJson("{$this->base}/documentos/{$docId}")->assertOk()->assertJsonPath('data.version_vigente', 2)
        ->assertJsonPath('data.emitido_en', '2026-05-12')->json('data');
    expect(collect($doc['vinculos'])->where('tipo', 'evento')->pluck('id')->sort()->values()->all())->toBe(collect($conf['evento_ids'])->sort()->values()->all())
        ->and($doc['versiones'][0]['archivos'][1]['sha256'])->toBe($shas[1]);

    // Idempotente y auditable: reconfirmar no duplica; la evidencia no se edita.
    $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertOk()->assertJsonCount(2, 'data.evento_ids');
    expect(DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => DB::table('borrador_extracciones')->update(['modelo' => 'otro'])))->toThrow(Exception::class)
        ->and(fn () => DB::transaction(fn () => DB::table('borrador_correcciones')->delete()))->toThrow(Exception::class);
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'fecha', 'estado' => 'correcto']]])->assertStatus(409);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('acta histórica 879/2002 con discrepancias: advierte sin autocorregir y exige confirmar las advertencias', function () {
    rodeo($this);
    [$docId] = documentoCapturado($this, [paginaJpeg('879')]);
    FakeExtractor::$salida = salidaActa([
        'variante' => '879_2002', 'problemas' => [['pagina' => 1, 'descripcion' => 'Reflejo sobre la tabla']],
        'campos' => ['renspa' => leido('18.105.0.00999/00'), 'firma_vacunador' => leido('no')],
        'categorias' => [['existentes' => leido('10'), 'vacunados_aftosa' => leido('12')]],
        'totales' => ['existentes' => leido('99')],
    ]);
    $id = (string) Str::uuid7();
    $b = $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'para_revisar')->assertJsonPath('data.esquema', ActaVacunacion::ESQUEMA_879)->json('data');
    $rutas = array_column($b['campos'], 'ruta');
    $codigos = array_column($b['advertencias'], 'codigo');
    expect($rutas)->not->toContain('alcance')->not->toContain('tipo_acto')
        ->and($codigos)->toContain('vacunados_mayor_existentes', 'totales_no_cierran', 'renspa_distinto', 'existencias_distintas', 'firma_no_detectada', 'calidad_imagen')
        // Nunca corrige el número leído.
        ->and(collect($b['campos'])->firstWhere('ruta', 'categorias.0.vacunados_aftosa')['valor'])->toBe(12);

    revisarTodo($this, $id);
    $this->postJson("{$this->base}/borradores/{$id}/confirmar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')
        ->assertJsonFragment(['codigo' => 'renspa_distinto']);
    // Corregir el dato recalcula las advertencias.
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'categorias.0.existentes', 'estado' => 'editado', 'valor' => 12]]])
        ->assertOk()->assertJsonMissing(['codigo' => 'vacunados_mayor_existentes']);
    $this->postJson("{$this->base}/borradores/{$id}/confirmar", ['confirmar_advertencias' => true])->assertOk()->assertJsonPath('data.estado', 'confirmado');
});

it('errores del proveedor: reintenta lo transitorio, queda fallido, se vuelve a leer a mano; sin proveedor no inventa; se agregan filas que el modelo no vio', function () {
    [$docId] = documentoCapturado($this, [paginaJpeg('x')]);
    FakeExtractor::$falla = new ExtraccionFallida('Servicio caído.', true);
    $id = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'fallido')->assertJsonPath('data.intentos', 3)->assertJsonPath('data.error', 'Servicio caído.');
    expect(FakeExtractor::$llamadas)->toHaveCount(3)->and(DB::table('borrador_extracciones')->count())->toBe(0);

    FakeExtractor::$falla = null;
    FakeExtractor::$salida = [...salidaActa(), 'categorias' => [], 'vacunas' => []];
    $this->postJson("{$this->base}/borradores/{$id}/reextraer")->assertStatus(202)->assertJsonPath('data.estado', 'para_revisar');
    $b = $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [], 'agregar_fila' => 'categorias'])->assertOk()->json('data');
    expect(collect($b['campos'])->where('grupo', 'categorias')->pluck('ruta')->all())
        ->toBe(['categorias.0.especie', 'categorias.0.categoria', 'categorias.0.categoria_codigo', 'categorias.0.existentes', 'categorias.0.vacunados_aftosa', 'categorias.0.vacunados_brucelosis']);
    $this->patchJson("{$this->base}/borradores/{$id}/campos", ['campos' => [['ruta' => 'categorias.0.categoria_codigo', 'estado' => 'editado', 'valor' => 'BUB_BUFALA'],
        ['ruta' => 'categorias.0.especie', 'estado' => 'editado', 'valor' => 'BOV']]])->assertOk()->assertJsonFragment(['codigo' => 'categoria_otra_especie']);
    revisarTodo($this, $id);
    $this->postJson("{$this->base}/borradores/{$id}/confirmar", ['confirmar_advertencias' => true])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    // Sin key no hay lectura: queda fallido con un mensaje claro, nunca datos inventados.
    config(['tropilla.extraccion.driver' => 'deshabilitado']);
    $otro = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $otro, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'fallido')->assertJsonPath('data.intentos', 1)->assertJsonPath('data.campos', []);
    $this->postJson("{$this->base}/borradores/{$otro}/descartar")->assertOk()->assertJsonPath('data.estado', 'descartado');
    $this->postJson("{$this->base}/borradores/{$otro}/confirmar")->assertStatus(409);
    $this->getJson("{$this->base}/borradores?estado=descartado")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otro);
});

it('permisos, pertenencia, dependencia y validación', function () {
    [$docId] = documentoCapturado($this, [paginaJpeg('p')]);
    FakeExtractor::$salida = salidaActa();
    $body = fn (array $x = []) => ['id' => (string) Str::uuid7(), 'documento_id' => $docId, 'familia' => 'acta_vacunacion', ...$x];

    $this->postJson("{$this->base}/borradores", $body(['familia' => 'otra']))->assertStatus(422)->assertJsonPath('code', 'validacion');
    $this->postJson("{$this->base}/borradores", ['documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(422);
    // El documento todavía no llegó por el push: la app reintenta.
    $this->postJson("{$this->base}/borradores", $body(['documento_id' => (string) Str::uuid7()]))->assertStatus(409)->assertJsonPath('code', 'dependencia_faltante');
    $ok = $body();
    $this->postJson("{$this->base}/borradores", $ok)->assertStatus(202);
    $this->postJson("{$this->base}/borradores", [...$ok, 'documento_id' => (string) Str::uuid7()])->assertStatus(409)->assertJsonPath('code', 'id_reutilizado');

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/borradores", $body())->assertStatus(403);
    $this->getJson("{$this->base}/borradores/{$ok['id']}")->assertOk();
    $this->postJson("{$this->base}/borradores/{$ok['id']}/confirmar")->assertStatus(403);

    // Otro establecimiento: ni lo ve ni puede usar el documento ajeno.
    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("{$this->base}/borradores/{$ok['id']}")->assertStatus(404);
    $this->getJson("/api/v1/establecimientos/{$otro->id}/borradores/{$ok['id']}")->assertStatus(404);
    $this->postJson("/api/v1/establecimientos/{$otro->id}/borradores", $body())->assertStatus(404);
});

it('normaliza fechas y categorías del acta contra el catálogo', function () {
    expect(ActaVacunacion::fecha('12/05/26'))->toBe('2026-05-12')
        ->and(ActaVacunacion::fecha('3-11-2025'))->toBe('2025-11-03')
        ->and(ActaVacunacion::fecha('31/02/2026'))->toBeNull()
        ->and(ActaVacunacion::fecha('mayo'))->toBeNull()
        ->and(ActaVacunacion::mapearCategoria('Vaquillonas', 'BOV'))->toBe('BOV_VAQUILLONA')
        ->and(ActaVacunacion::mapearCategoria('BUEYES', 'BOV'))->toBe('BOV_BUEY')
        ->and(ActaVacunacion::mapearCategoria('Búfalas', 'BUB'))->toBe('BUB_BUFALA')
        ->and(ActaVacunacion::mapearCategoria('Terneros/as', 'BOV'))->toBeNull();
});

it('driver Claude: manda las páginas como imagen/PDF base64 con salida estructurada al modelo configurado y lee la respuesta', function () {
    $pedidos = [];
    $responder = function (int $status, array $cuerpo) use (&$pedidos) {
        return function ($req) use (&$pedidos, $status, $cuerpo) {
            $pedidos[] = ['url' => (string) $req->getUri(), 'key' => $req->getHeaderLine('x-api-key'), 'body' => json_decode((string) $req->getBody(), true)];

            return new Response($status, ['content-type' => 'application/json'], json_encode($cuerpo));
        };
    };
    $mensaje = fn (string $texto, string $stop = 'end_turn') => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-4-5-20251001',
        'content' => [['type' => 'text', 'text' => $texto]], 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1200, 'output_tokens' => 300]];
    $familia = new ActaVacunacion;
    $cliente = fn ($mw) => new Client(apiKey: 'sk-test', requestOptions: ['middleware' => [$mw], 'maxRetries' => 0]);

    $ext = new ClaudeExtractor('sk-test', 'claude-haiku-4-5-20251001', 8192, $cliente($responder(200, $mensaje(json_encode(salidaActa())))));
    $r = $ext->extraer([['mime' => 'image/jpeg', 'bytes' => 'JPEG'], ['mime' => 'application/pdf', 'bytes' => '%PDF']], $familia->instrucciones(), $familia->esquemaSalida());
    expect($r->salida['variante'])->toBe('711_2025')->and($r->modelo)->toBe('claude-haiku-4-5-20251001')
        ->and($r->tokensEntrada)->toBe(1200)->and($r->tokensSalida)->toBe(300);
    $body = $pedidos[0]['body'];
    expect($pedidos[0]['url'])->toEndWith('/v1/messages')->and($pedidos[0]['key'])->toBe('sk-test')
        ->and($body['model'])->toBe('claude-haiku-4-5-20251001')->and($body['max_tokens'])->toBe(8192)
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toContain('categorias')
        ->and($body['messages'][0]['content'][1])->toBe(['type' => 'image', 'source' => ['type' => 'base64', 'data' => base64_encode('JPEG'), 'media_type' => 'image/jpeg']])
        ->and($body['messages'][0]['content'][3]['type'])->toBe('document');

    // Respuesta cortada o error del proveedor → ExtraccionFallida (5xx/429 reintentable; 4xx no).
    $corto = new ClaudeExtractor('sk-test', 'm', 10, $cliente($responder(200, $mensaje('{"var', 'max_tokens'))));
    expect(fn () => $corto->extraer([['mime' => 'image/png', 'bytes' => 'x']], 'i', []))->toThrow(ExtraccionFallida::class);
    $caido = new ClaudeExtractor('sk-test', 'm', 10, $cliente($responder(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']])));
    try {
        $caido->extraer([['mime' => 'image/png', 'bytes' => 'x']], 'i', []);
        $this->fail('Tenía que fallar');
    } catch (ExtraccionFallida $e) {
        expect($e->reintentable)->toBeTrue();
    }
    expect(fn () => $caido->extraer([['mime' => 'text/plain', 'bytes' => 'x']], 'i', []))->toThrow(ExtraccionFallida::class);
});

/** Respuesta de `generateContent` con el texto dado. */
function respuestaGemini(string $texto, string $fin = 'STOP'): array
{
    return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'pensando…', 'thought' => true], ['text' => $texto]]], 'finishReason' => $fin]],
        'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => 420], 'modelVersion' => 'gemini-2.5-flash-preview'];
}

it('driver Gemini: manda todas las páginas inline con su mime, salida JSON con responseSchema derivado del esquema y lee la respuesta', function () {
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaGemini(json_encode(salidaActa())))]);
    $familia = new ActaVacunacion;

    $r = (new GeminiExtractor('g-test', 'gemini-flash-latest'))->extraer(
        [['mime' => 'image/jpeg', 'bytes' => 'JPEG'], ['mime' => 'image/png', 'bytes' => 'PNG'], ['mime' => 'application/pdf', 'bytes' => '%PDF']],
        $familia->instrucciones(), $familia->esquemaSalida());

    expect($r->salida)->toBe(salidaActa())->and($r->proveedor)->toBe('google')->and($r->modelo)->toBe('gemini-2.5-flash-preview')
        ->and($r->tokensEntrada)->toBe(1500)->and($r->tokensSalida)->toBe(420);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $req) use ($familia) {
        $b = $req->data();
        $partes = $b['contents'][0]['parts'];
        $esq = $b['generationConfig']['responseSchema'];
        $campo = $esq['properties']['campos']['properties']['numero_acta'];

        return $req->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent'
            && $req->method() === 'POST' && $req->header('x-goog-api-key') === ['g-test'] && ! str_contains($req->url(), 'key=')
            && $partes[1] === ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('JPEG')]]
            && $partes[3]['inlineData']['mimeType'] === 'image/png' && $partes[5]['inlineData']['mimeType'] === 'application/pdf'
            && $partes[0]['text'] === 'Página 1:' && end($partes)['text'] === $familia->instrucciones()
            && str_contains($b['systemInstruction']['parts'][0]['text'], 'Nunca completás')
            && $b['generationConfig']['responseMimeType'] === 'application/json' && $b['generationConfig']['temperature'] === 0
            && $b['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']
            && $esq['type'] === 'OBJECT' && $esq['required'] === ['variante', 'problemas', 'campos', 'vacunas', 'categorias', 'totales']
            && $esq['properties']['variante'] === ['type' => 'STRING', 'enum' => ['711_2025', '879_2002', 'desconocida']]
            && $esq['properties']['vacunas']['type'] === 'ARRAY' && $esq['properties']['vacunas']['items']['propertyOrdering'] === ['enfermedad', 'producto', 'serie', 'vencimiento']
            && $campo['properties']['valor'] === ['type' => 'STRING', 'nullable' => true]
            && $campo['properties']['pagina'] === ['type' => 'INTEGER', 'nullable' => true]
            && ! str_contains(json_encode($esq), 'additionalProperties');
    });
});

it('driver Gemini: con un esquema que Gemini no representa lo manda como instrucción y valida la respuesta igual', function () {
    Http::preventStrayRequests();
    $esquema = ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]]];
    Http::fakeSequence()->push(respuestaGemini('{"x": "1"}'))->push(respuestaGemini('no es json'))->push(respuestaGemini('[1, 2]'));
    $ext = new GeminiExtractor('g-test', 'gemini-2.5-flash', razonamiento: '');

    expect($ext->extraer([['mime' => 'image/webp', 'bytes' => 'W']], 'Leé.', $esquema)->salida)->toBe(['x' => '1']);
    Http::assertSent(fn (Request $req) => ! isset($req['generationConfig']['responseSchema']) && ! isset($req['generationConfig']['thinkingConfig'])
        && $req['generationConfig']['responseMimeType'] === 'application/json'
        && str_contains(Arr::last($req['contents'][0]['parts'])['text'], '"anyOf"'));
    expect(fn () => $ext->extraer([['mime' => 'image/webp', 'bytes' => 'W']], 'Leé.', $esquema))->toThrow(ExtraccionFallida::class, 'La lectura no devolvió datos válidos.')
        ->and(fn () => $ext->extraer([['mime' => 'image/webp', 'bytes' => 'W']], 'Leé.', $esquema))->toThrow(ExtraccionFallida::class, 'La lectura no devolvió datos válidos.');
    expect(fn () => GeminiExtractor::esquemaGemini(['type' => ['string', 'integer']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => GeminiExtractor::esquemaGemini(['type' => 'object', '$ref' => '#/x']))->toThrow(InvalidArgumentException::class);
});

it('driver Gemini: errores, cortes y timeouts terminan en ExtraccionFallida (sin datos), reintentando solo lo transitorio', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $ext = new GeminiExtractor('g-test', 'gemini-flash-latest', reintentos: 2);
    $pagina = [['mime' => 'image/jpeg', 'bytes' => 'J']];
    // Un solo fake (los stubs de Http::fake se acumulan): cada caso cambia `$responder`.
    $responder = null;
    Http::fake(function ($req) use (&$responder) {
        return $responder($req);
    });
    $falla = function (callable $fn): ExtraccionFallida {
        try {
            $fn();
        } catch (ExtraccionFallida $e) {
            return $e;
        }
        throw new RuntimeException('Tenía que fallar');
    };

    // 503 persistente: 3 intentos (1 + 2 reintentos con espera) y queda reintentable para el job.
    $responder = fn () => Http::response(['error' => ['code' => 503, 'message' => 'overloaded']], 503);
    expect($falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']))->reintentable)->toBeTrue();
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);

    // 429 seguido de éxito: se recupera solo.
    $secuencia = Http::sequence()->push(['error' => ['code' => 429]], 429)->push(respuestaGemini('{"a": 1}'));
    $responder = $secuencia;
    expect($ext->extraer($pagina, 'i', ['type' => 'object'])->salida)->toBe(['a' => 1]);

    // 400 (key/modelo/pedido inválido): no se reintenta.
    $responder = fn () => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400);
    $e = $falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']));
    expect($e->reintentable)->toBeFalse()->and($e->getMessage())->toContain('400');
    Http::assertSentCount(6);

    // Timeout / conexión caída.
    $responder = Http::failedConnection('cURL error 28: Operation timed out');
    expect($falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']))->reintentable)->toBeTrue();

    // Respuesta cortada, bloqueada o sin candidatos.
    $responder = fn () => Http::response(respuestaGemini('{"variante": "711', 'MAX_TOKENS'));
    expect($falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']))->getMessage())->toContain('demasiado largo');
    $responder = fn () => Http::response(respuestaGemini('{}', 'SAFETY'));
    expect($falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']))->reintentable)->toBeFalse();
    $responder = fn () => Http::response(['promptFeedback' => ['blockReason' => 'OTHER']]);
    expect($falla(fn () => $ext->extraer($pagina, 'i', ['type' => 'object']))->reintentable)->toBeFalse();

    // Formato no soportado: ni siquiera se llama.
    $enviados = count(Http::recorded());
    expect(fn () => $ext->extraer([['mime' => 'image/gif', 'bytes' => 'G']], 'i', ['type' => 'object']))->toThrow(ExtraccionFallida::class)
        ->and(Http::recorded())->toHaveCount($enviados);
});

it('driver Gemini de punta a punta: el borrador guarda proveedor, modelo y salida cruda; si Gemini falla queda fallido sin campos', function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['tropilla.extraccion.driver' => 'gemini', 'tropilla.extraccion.gemini_key' => 'g-test', 'tropilla.extraccion.gemini_modelo' => 'gemini-flash-latest']);
    expect(app(Extractor::class))->toBeInstanceOf(GeminiExtractor::class);
    [$docId] = documentoCapturado($this, [paginaJpeg('g1'), paginaJpeg('g2')]);

    $responder = fn () => Http::response(respuestaGemini(json_encode(salidaActa())));
    Http::fake(function () use (&$responder) {
        return $responder();
    });
    $id = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'para_revisar')->assertJsonPath('data.extraccion.proveedor', 'google')
        ->assertJsonPath('data.extraccion.modelo', 'gemini-2.5-flash-preview');
    Http::assertSent(fn (Request $req) => count(array_filter($req['contents'][0]['parts'], fn ($p) => isset($p['inlineData']))) === 2);
    $ext = DB::table('borrador_extracciones')->where('borrador_id', $id)->first();
    expect(json_decode($ext->salida, true))->toEqual(salidaActa())->and($ext->tokens_entrada)->toBe(1500);

    $responder = fn () => Http::response(['error' => ['code' => 500]], 500);
    $otro = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $otro, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202)
        ->assertJsonPath('data.estado', 'fallido')->assertJsonPath('data.campos', []);
    expect(DB::table('borrador_extracciones')->where('borrador_id', $otro)->count())->toBe(0);
});

it('driver por defecto: gemini si hay GEMINI_API_KEY, si no claude si hay ANTHROPIC_API_KEY, si no deshabilitado', function () {
    $driver = function (array $env): string {
        foreach (['TROPILLA_EXTRACCION_DRIVER', 'GEMINI_API_KEY', 'ANTHROPIC_API_KEY'] as $k) {
            $v = $env[$k] ?? null;
            $v === null ? putenv($k) : putenv("{$k}={$v}");
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }

        return (require config_path('tropilla.php'))['extraccion']['driver'];
    };
    try {
        expect($driver(['GEMINI_API_KEY' => 'g', 'ANTHROPIC_API_KEY' => 'a']))->toBe('gemini')
            ->and($driver(['ANTHROPIC_API_KEY' => 'a']))->toBe('claude')
            ->and($driver([]))->toBe('deshabilitado')
            ->and($driver(['TROPILLA_EXTRACCION_DRIVER' => 'claude', 'GEMINI_API_KEY' => 'g']))->toBe('claude');
    } finally {
        $driver(['TROPILLA_EXTRACCION_DRIVER' => 'fake']);
    }
});

it('cola: retry_after supera el timeout de la extracción (no se reentrega mientras corre) y un timeout deja el borrador fallido', function () {
    $job = new ExtraerBorradorJob('x');
    foreach (['redis', 'database', 'beanstalkd'] as $con) {
        expect(config("queue.connections.{$con}.retry_after"))->toBeGreaterThan($job->timeout + 30);
    }
    expect($job->tries)->toBe(1)->and($job->failOnTimeout)->toBeTrue();

    [$docId] = documentoCapturado($this, [paginaJpeg('t')]);
    $id = (string) Str::uuid7();
    $this->postJson("{$this->base}/borradores", ['id' => $id, 'documento_id' => $docId, 'familia' => 'acta_vacunacion'])->assertStatus(202);
    DB::table('borradores_documento')->where('id', $id)->update(['estado' => 'extrayendo']);
    (new ExtraerBorradorJob($id))->failed(new RuntimeException('timeout'));
    $this->getJson("{$this->base}/borradores/{$id}")->assertOk()->assertJsonPath('data.estado', 'fallido')
        ->assertJsonPath('data.error', 'La lectura tardó demasiado. Probá leerla de nuevo.');
});
