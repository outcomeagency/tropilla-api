<?php

use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use App\Domain\Notificaciones\Notificador;
use App\Domain\Telegram\Asistente\Herramientas;
use App\Domain\Telegram\Contexto;
use App\Domain\Telegram\Documentos\CerrarAlbumJob;
use App\Domain\Telegram\Documentos\RecibirDocumento;
use App\Domain\Telegram\TelegramApi;
use App\Domain\Telegram\Vinculos;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| TPA-77 — Bot de Telegram del productor. Telegram y Gemini con Http::fake; la lectura de actas con el FakeExtractor.
*/

const TG_TOKEN = '123456:TEST-token-secreto';
const TG_SECRETO = 'secreto_webhook_de_prueba_123';
const TG_CHAT = 5550001;

beforeEach(function () {
    Storage::fake();
    config([
        'tropilla.telegram.token' => TG_TOKEN, 'tropilla.telegram.webhook_secret' => TG_SECRETO,
        'tropilla.asistente.gemini_key' => 'gemini-key-de-prueba', 'tropilla.asistente.modelo' => 'gemini-flash-latest',
        'tropilla.archivos.driver' => 'local', 'tropilla.extraccion.driver' => 'fake',
    ]);
    FakeExtractor::reset();
    $this->travelTo('2026-10-16 15:00:00');
    $this->est = crearEstablecimiento(extra: ['renspa' => '18.105.0.00001/00']);
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->updateId = 1000;
    $this->gemini = [];
    $this->archivo = 'bytes';
    $t = $this;
    Http::fake([
        'api.telegram.org/bot*/getFile' => fn () => Http::response(['ok' => true, 'result' => ['file_id' => 'f', 'file_size' => strlen($t->archivo), 'file_path' => 'photos/file_1.jpg']]),
        'api.telegram.org/file/*' => fn () => Http::response($t->archivo),
        'api.telegram.org/*' => fn () => Http::response(['ok' => true, 'result' => ['message_id' => random_int(100, 999_999)]]),
        'generativelanguage.googleapis.com/*' => fn () => Http::response(array_shift($t->gemini) ?? geminiTexto('(sin respuesta programada)')),
    ]);
});

function tgUpdate(object $t, array $contenido): array
{
    return ['update_id' => ++$t->updateId] + $contenido;
}

function tgMensaje(object $t, array $m, int $chat = TG_CHAT, int $de = 9001): array
{
    return tgUpdate($t, ['message' => ['message_id' => random_int(1, 9999), 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'private'],
        'from' => ['id' => $de, 'is_bot' => false, 'first_name' => 'Ramón', 'username' => 'ramon_campo']] + $m]);
}

function tgTexto(object $t, string $texto, int $chat = TG_CHAT, int $de = 9001): void
{
    tgPost($t, tgMensaje($t, ['text' => $texto], $chat, $de))->assertOk();
}

function tgBoton(object $t, string $data, int $chat = TG_CHAT, int $de = 9001): void
{
    tgPost($t, tgUpdate($t, ['callback_query' => ['id' => 'cb'.random_int(1, 99999), 'from' => ['id' => $de], 'data' => $data,
        'message' => ['message_id' => 4242, 'chat' => ['id' => $chat, 'type' => 'private']]]]))->assertOk();
}

function tgPost(object $t, array $update, ?string $secreto = TG_SECRETO)
{
    return $t->postJson('/api/v1/telegram/webhook', $update, $secreto === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secreto]);
}

/** Textos que mandó (o editó) el bot, en orden. @return list<string> */
function tgEnviados(): array
{
    return collect(Http::recorded())->filter(fn ($p) => preg_match('#/(sendMessage|editMessageText)$#', $p[0]->url()))
        ->map(fn ($p) => (string) $p[0]->data()['text'])->values()->all();
}

/** Último teclado inline mandado. @return list<string> callback_data */
function tgBotones(): array
{
    $ult = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/sendMessage') && isset($p[0]->data()['reply_markup']['inline_keyboard']))->last();

    return $ult ? collect($ult[0]->data()['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all() : [];
}

/** @return list<array<string, mixed>> cuerpos enviados a Gemini */
function geminiPedidos(): array
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'generativelanguage.googleapis.com'))->map(fn ($p) => $p[0]->data())->values()->all();
}

function geminiLlamada(string $nombre, array $args = []): array
{
    return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $nombre, 'args' => (object) $args], 'thoughtSignature' => 'sig']]], 'finishReason' => 'STOP']],
        'modelVersion' => 'gemini-flash-latest'];
}

function geminiTexto(string $texto): array
{
    return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $texto]]], 'finishReason' => 'STOP']]];
}

/** Vincula el chat de prueba al usuario (código desde la API + /start). */
function vincular(object $t, $usuario = null, int $chat = TG_CHAT, int $de = 9001): object
{
    Sanctum::actingAs($usuario ?? $t->dueno);
    $codigo = $t->postJson('/api/v1/me/telegram/codigo')->assertCreated()->json('data.codigo');
    tgTexto($t, "/start {$codigo}", $chat, $de);

    return DB::table('telegram_vinculos')->where('chat_id', $chat)->whereNull('desvinculado_en')->sole();
}

/** Búfala con caravana visual. */
function bufala(object $t, string $caravana = '1234'): string
{
    $a = animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => $caravana]]]);
    $t->postJson("{$t->base}/eventos", altaBody([$a], '2026-01-10'))->assertCreated();

    return $a['animal_id'];
}

it('vincula con un código de un solo uso y vida corta: deep link, establecimiento único, estado, avisos y desvincular desde el panel', function () {
    $this->getJson('/api/v1/me/telegram')->assertOk()->assertJsonPath('data.vinculado', false)->assertJsonPath('data.disponible', true)
        ->assertJsonPath('data.bot.url', 'https://t.me/tropilla_bot');
    $r = $this->postJson('/api/v1/me/telegram/codigo')->assertCreated();
    $codigo = $r->json('data.codigo');
    expect($codigo)->toMatch('/^[A-Z2-9]{10}$/')->and($r->json('data.url'))->toBe("https://t.me/tropilla_bot?start={$codigo}");
    expect(DB::table('telegram_codigos')->value('codigo_hash'))->toBe(hash('sha256', $codigo)); // solo el hash

    tgTexto($this, "/start {$codigo}");
    $v = DB::table('telegram_vinculos')->sole();
    expect($v->usuario_id)->toBe($this->dueno->id)->and($v->establecimiento_id)->toBe($this->est->id)->and($v->username)->toBe('ramon_campo');
    expect(collect(tgEnviados())->last())->toContain('quedó vinculado');
    $this->getJson('/api/v1/me/telegram')->assertOk()->assertJsonPath('data.vinculado', true)->assertJsonPath('data.avisos', true)
        ->assertJsonPath('data.establecimiento.nombre', 'La Esperanza');

    // El mismo código no sirve dos veces (otro chat).
    tgTexto($this, "/start {$codigo}", 777, 778);
    expect(DB::table('telegram_vinculos')->count())->toBe(1)->and(collect(tgEnviados())->last())->toContain('no es válido o venció');

    // Vence a los 10 minutos.
    $otro = $this->postJson('/api/v1/me/telegram/codigo')->json('data.codigo');
    $this->travel(11)->minutes();
    tgTexto($this, "/start {$otro}", 777, 778);
    expect(DB::table('telegram_vinculos')->whereNull('desvinculado_en')->sole()->chat_id)->toBe(TG_CHAT);

    $this->patchJson('/api/v1/me/telegram', ['avisos' => 'quizas'])->assertStatus(422);
    $this->patchJson('/api/v1/me/telegram', ['avisos' => false])->assertOk()->assertJsonPath('data.avisos', false);
    $this->deleteJson('/api/v1/me/telegram')->assertNoContent();
    $this->getJson('/api/v1/me/telegram')->assertJsonPath('data.vinculado', false);
    expect(DB::table('telegram_vinculos')->value('desvinculado_por'))->toBe('usuario_panel')
        ->and(DB::table('auditoria')->where('entidad', 'telegram_vinculo')->pluck('accion')->all())->toBe(['alta', 'avisos', 'baja']);

    // Desvinculado: el bot ya no responde datos, pide vincular.
    tgTexto($this, '¿cuántas vacas tengo?');
    expect(collect(tgEnviados())->last())->toContain('vinculá tu cuenta')->and(geminiPedidos())->toBe([]);
});

it('/salir desvincula; vincular otro chat reemplaza el anterior; /start sin código no vincula', function () {
    vincular($this);
    vincular($this, chat: 6660002, de: 9002);
    expect(DB::table('telegram_vinculos')->whereNull('desvinculado_en')->sole()->chat_id)->toBe(6660002)
        ->and(DB::table('telegram_vinculos')->where('chat_id', TG_CHAT)->value('desvinculado_por'))->toBe('nuevo_vinculo');

    tgTexto($this, '/salir', 6660002, 9002);
    expect(DB::table('telegram_vinculos')->whereNull('desvinculado_en')->count())->toBe(0);
    tgTexto($this, '/start', 6660002, 9002);
    expect(collect(tgEnviados())->last())->toContain('vinculá tu cuenta');
});

it('webhook: exige el secret_token (comparación constante), 404 sin configurar, idempotente por update_id y solo chats privados', function () {
    $u = tgMensaje($this, ['text' => '/ayuda']);
    tgPost($this, $u, null)->assertForbidden();
    tgPost($this, $u, 'otro')->assertForbidden();
    tgPost($this, $u)->assertOk();
    tgPost($this, $u)->assertOk(); // reintento de Telegram
    expect(DB::table('telegram_mensajes')->count())->toBe(1);

    $grupo = tgUpdate($this, ['message' => ['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'group'], 'from' => ['id' => 1], 'text' => 'hola']]);
    tgPost($this, $grupo)->assertOk();
    expect(DB::table('telegram_mensajes')->count())->toBe(1);

    config(['tropilla.telegram.webhook_secret' => null]);
    tgPost($this, tgMensaje($this, ['text' => 'x']))->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me/telegram')->assertUnauthorized();
});

it('con varios establecimientos pide /campo y solo deja elegir los propios', function () {
    $otro = crearEstablecimiento($this->dueno, ['nombre' => 'El Bañado']);
    $ajeno = crearEstablecimiento();
    $v = vincular($this);
    expect($v->establecimiento_id)->toBeNull()->and(tgBotones())->toContain("c:{$otro->id}")->toContain("c:{$this->est->id}")->not->toContain("c:{$ajeno->id}");

    tgTexto($this, '¿cuántas vacas tengo?');
    expect(collect(tgEnviados())->last())->toContain('Primero elegí')->and(geminiPedidos())->toBe([]);

    tgBoton($this, "c:{$ajeno->id}");
    expect(DB::table('telegram_vinculos')->value('establecimiento_id'))->toBeNull();
    tgBoton($this, "c:{$otro->id}");
    expect(DB::table('telegram_vinculos')->value('establecimiento_id'))->toBe($otro->id)->and(collect(tgEnviados())->last())->toBe('Trabajamos con El Bañado.');

    // Un botón de otro usuario de Telegram en el mismo chat no hace nada.
    tgBoton($this, "c:{$this->est->id}", de: 1);
    expect(DB::table('telegram_vinculos')->value('establecimiento_id'))->toBe($otro->id);
});

it('consulta en lenguaje natural: Gemini con function calling sobre herramientas de solo lectura, key por header y sin guardar el texto', function () {
    bufala($this);
    bufala($this, '555');
    vincular($this);
    $this->gemini = [geminiLlamada('existencias'), geminiTexto('Tenés 2 búfalas.')];

    tgTexto($this, '¿cuántas búfalas tengo?');

    expect(collect(tgEnviados())->last())->toBe('Tenés 2 búfalas.');
    $pedidos = geminiPedidos();
    expect($pedidos)->toHaveCount(2);
    $nombres = collect($pedidos[0]['tools'][0]['functionDeclarations'])->pluck('name')->all();
    expect($nombres)->toContain('existencias', 'buscar_animal', 'reproduccion', 'vencimientos', 'alertas_clima', 'ofertas', 'proponer_carga');
    expect($pedidos[0]['systemInstruction']['parts'][0]['text'])->toContain('Nunca inventes')->toContain('La Esperanza');
    $respuesta = $pedidos[1]['contents'][2]['parts'][0]['functionResponse'];
    expect($respuesta['name'])->toBe('existencias')->and($respuesta['response']['resultado']['total_cabezas'])->toBe(2)
        ->and($pedidos[1]['contents'][1]['parts'][0]['thoughtSignature'])->toBe('sig');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'models/gemini-flash-latest:generateContent') && $r->hasHeader('x-goog-api-key', 'gemini-key-de-prueba')
        && ! str_contains($r->url(), 'gemini-key-de-prueba'));

    $log = DB::table('telegram_mensajes')->where('accion', 'consulta')->sole();
    expect(json_decode($log->detalle, true))->toBe(['largo' => 23, 'herramientas' => ['existencias']])
        ->and(DB::table('telegram_mensajes')->whereRaw('detalle::text ILIKE ?', ['%búfalas tengo%'])->count())->toBe(0);
});

it('el asistente respeta el rol: lectura no carga y el encargado no ve ofertas; sin datos lo dice el modelo con lo que devuelve la herramienta', function () {
    $lector = miembro($this->est, 'lectura');
    vincular($this, $lector);
    $this->gemini = [geminiLlamada('ofertas'), geminiTexto('No tengo ese dato.')];
    tgTexto($this, '¿qué ofertas tengo publicadas?');
    $p = geminiPedidos();
    $nombres = collect($p[0]['tools'][0]['functionDeclarations'])->pluck('name')->all();
    expect($nombres)->not->toContain('proponer_carga')->not->toContain('ofertas')
        ->and($p[1]['contents'][2]['parts'][0]['functionResponse']['response']['resultado'])->toHaveKey('error');

    $enc = miembro($this->est, 'encargado');
    vincular($this, $enc, 7770003, 9003);
    $this->gemini = [geminiLlamada('buscar_animal', ['identificacion' => '9999']), geminiTexto('No encontré esa caravana.')];
    tgTexto($this, '¿dónde está la 9999?', 7770003, 9003);
    $p = array_slice(geminiPedidos(), 2);
    expect(collect($p[0]['tools'][0]['functionDeclarations'])->pluck('name')->all())->toContain('proponer_carga')->not->toContain('ofertas')
        ->and($p[1]['contents'][2]['parts'][0]['functionResponse']['response']['resultado'])->toBe(['encontrados' => 0, 'animales' => []]);
});

it('carga rápida por texto: propone, pide confirmación con botones y recién al confirmar registra por RegistrarEvento con origen telegram', function () {
    $id = bufala($this);
    vincular($this);
    $this->gemini = [geminiLlamada('proponer_carga', ['tipo' => 'pesaje', 'caravanas' => ['1234'], 'peso_kg' => 412.5])];

    tgTexto($this, 'pesé la 1234, 412 y medio');

    expect(collect(tgEnviados())->last())->toContain('Voy a registrar en La Esperanza')->toContain('Pesaje · caravana 1234')->toContain('412.5 kg')->toContain('16/10/2026');
    $p = DB::table('telegram_propuestas')->sole();
    expect($p->estado)->toBe('pendiente')->and(tgBotones())->toBe(["e:{$p->id}:si", "e:{$p->id}:no"])
        ->and(DB::table('eventos')->where('tipo', 'hacienda.pesaje')->count())->toBe(0);

    tgBoton($this, "e:{$p->id}:si");
    $ev = DB::table('eventos')->where('tipo', 'hacienda.pesaje')->sole();
    expect($ev->origen)->toBe('telegram')->and($ev->autor_id)->toBe($this->dueno->id)->and(json_decode($ev->payload, true))->toBe(['modo' => 'individual', 'metodo' => 'balanza', 'peso_kg' => '412.5'])
        ->and(json_decode($ev->sobre, true)['origen'])->toBe('telegram')
        ->and(DB::table('animales_estado')->where('animal_id', $id)->value('peso_kg'))->toEqual('412.5')
        ->and(DB::table('telegram_propuestas')->value('estado'))->toBe('confirmada');
    expect(collect(tgEnviados())->last())->toContain('Listo, quedó registrado');

    // Doble toque: no duplica.
    tgBoton($this, "e:{$p->id}:si");
    expect(DB::table('eventos')->where('tipo', 'hacienda.pesaje')->count())->toBe(1);
    $this->getJson("{$this->base}/eventos/{$ev->id}")->assertOk()->assertJsonPath('data.evento.origen', 'telegram');
});

it('carga rápida: cancelar no registra; caravana inexistente vuelve al modelo; advertencias se preguntan antes de registrar igual', function () {
    bufala($this);
    vincular($this);
    $this->gemini = [geminiLlamada('proponer_carga', ['tipo' => 'muerte', 'caravanas' => ['1234'], 'causa' => 'rayo'])];
    tgTexto($this, 'se murió la 1234 por un rayo');
    $p = DB::table('telegram_propuestas')->sole();
    tgBoton($this, "e:{$p->id}:no");
    expect(DB::table('eventos')->where('tipo', 'hacienda.muerte')->count())->toBe(0)->and(DB::table('telegram_propuestas')->value('estado'))->toBe('cancelada');

    $this->gemini = [geminiLlamada('proponer_carga', ['tipo' => 'pesaje', 'caravanas' => ['8888'], 'peso_kg' => 300]), geminiTexto('No encontré la 8888, ¿la revisás?')];
    tgTexto($this, 'pesé la 8888, 300 kilos');
    expect(collect(tgEnviados())->last())->toBe('No encontré la 8888, ¿la revisás?')
        ->and(geminiPedidos()[2]['contents'][2]['parts'][0]['functionResponse']['response']['resultado']['error'])->toContain('No encontré la caravana 8888');

    // Pesaje atípico (> X % en pocos días) → advertencia → «Registrar igual».
    $this->gemini = [geminiLlamada('proponer_carga', ['tipo' => 'pesaje', 'caravanas' => ['1234'], 'peso_kg' => 400])];
    tgTexto($this, 'pesé la 1234, 400');
    tgBoton($this, 'e:'.DB::table('telegram_propuestas')->where('estado', 'pendiente')->value('id').':si');
    $this->gemini = [geminiLlamada('proponer_carga', ['tipo' => 'pesaje', 'caravanas' => ['1234'], 'peso_kg' => 150])];
    tgTexto($this, 'pesé la 1234, 150');
    $p = DB::table('telegram_propuestas')->where('estado', 'pendiente')->sole();
    tgBoton($this, "e:{$p->id}:si");
    expect(collect(tgEnviados())->last())->toContain('Ojo:')->toContain('¿Lo registro igual?')
        ->and(DB::table('eventos')->where('tipo', 'hacienda.pesaje')->count())->toBe(1);
    tgBoton($this, "e:{$p->id}:forzar");
    expect(DB::table('eventos')->where('tipo', 'hacienda.pesaje')->where('origen', 'telegram')->count())->toBe(2);
});

it('nacimiento por audio: transcribe con Gemini, propone el parto de la madre con la cría y registra al confirmar', function () {
    $madre = bufala($this);
    vincular($this);
    $this->archivo = 'OggS-audio';
    $this->gemini = [
        geminiTexto('nació una bucerra de la 1234, le puse la 77, pesó 32 kilos'),
        geminiLlamada('proponer_carga', ['tipo' => 'nacimiento', 'caravanas' => ['1234'], 'sexo_cria' => 'H', 'caravana_cria' => '77', 'peso_kg' => 32]),
    ];
    tgPost($this, tgMensaje($this, ['voice' => ['file_id' => 'v1', 'duration' => 6, 'mime_type' => 'audio/ogg', 'file_size' => 10]]))->assertOk();

    $p = geminiPedidos();
    expect($p[0]['contents'][0]['parts'][0]['inlineData'])->toBe(['mimeType' => 'audio/ogg', 'data' => base64_encode('OggS-audio')]);
    $textos = tgEnviados();
    expect($textos[count($textos) - 2])->toBe('Entendí: «nació una bucerra de la 1234, le puse la 77, pesó 32 kilos»')
        ->and(end($textos))->toContain('Parto · madre caravana 1234')->toContain('cría hembra viva, caravana 77, 32 kg');

    tgBoton($this, 'e:'.DB::table('telegram_propuestas')->value('id').':si');
    $ev = DB::table('eventos')->where('tipo', 'reproduccion.parto')->sole();
    $cria = json_decode($ev->payload, true)['crias'][0];
    expect($ev->origen)->toBe('telegram')->and($cria['animal']['categoria'])->toBe('BUB_BUCERRA')->and($cria['animal']['madre_id'])->toBe($madre)
        ->and(DB::table('animal_identificaciones')->where('animal_id', $cria['animal']['animal_id'])->value('valor'))->toBe('77');
    expect(DB::table('telegram_mensajes')->where('tipo', 'audio')->where('accion', 'consulta')->count())->toBe(1);
});

/** Acta 711/2025 legible: 3 vacas + 2 terneras, con dos lecturas dudosas (vacunador y brucelosis de terneras). */
function actaTelegram(): array
{
    $l = fn (?string $v, float $c = 0.95, array $alt = []) => ['valor' => $v, 'confianza' => $c, 'pagina' => 1, 'alternativas' => $alt];

    return [
        'variante' => '711_2025', 'problemas' => [],
        'campos' => [
            'numero_acta' => $l('A-0012'), 'tipo_acto' => $l('Sistemática'), 'alcance' => $l('Total'), 'campana' => $l('1.ª 2026'),
            'fecha' => $l('12/05/26', 0.9), 'renspa' => $l('18.105.0.00001/00'), 'establecimiento' => $l('La Esperanza'), 'titular' => $l('Don Ramón S.A.'),
            'cuit_titular' => $l(null, 0.2), 'ente_sanitario' => $l('FUCOSA'), 'vacunador_nombre' => $l('Juan Pérez', 0.6), 'vacunador_matricula' => $l('V-345'),
            'responsable_nombre' => $l('Ramón Gómez'), 'responsable_dni' => $l('20.123.456'), 'firma_responsable' => $l('si'), 'firma_vacunador' => $l('si'),
            'observaciones' => $l(null, 0.1),
        ],
        'vacunas' => [
            ['enfermedad' => $l('Aftosa'), 'producto' => $l('Bioaftogen'), 'serie' => $l('L-881'), 'vencimiento' => $l('31/12/2026')],
            ['enfermedad' => $l('Brucelosis'), 'producto' => $l('Cepa 19'), 'serie' => $l('B-22'), 'vencimiento' => $l(null, 0.1)],
        ],
        'categorias' => [
            ['especie' => $l('Bovino'), 'categoria' => $l('Vacas'), 'existentes' => $l('3'), 'vacunados_aftosa' => $l('3'), 'vacunados_brucelosis' => $l(null, 0.1)],
            ['especie' => $l('Bovino'), 'categoria' => $l('Terneras'), 'existentes' => $l('2'), 'vacunados_aftosa' => $l('2'), 'vacunados_brucelosis' => $l('2', 0.55, ['7'])],
        ],
        'totales' => ['existentes' => $l('5'), 'vacunados' => $l('5')],
    ];
}

it('foto de un acta → mismo borrador de TPA-61/62 → resumen + solo las dudas con botones → corrección escrita → confirmación explícita → vacunaciones con origen telegram', function () {
    $animales = [...array_map(fn () => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']), range(1, 3)),
        ...array_map(fn () => animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_TERNERA']), range(1, 2))];
    $this->postJson("{$this->base}/eventos", altaBody($animales, '2026-01-10'))->assertCreated();
    vincular($this);
    $img = imagecreatetruecolor(120, 40);
    ob_start();
    imagejpeg($img);
    $this->archivo = (string) ob_get_clean();
    FakeExtractor::$salida = actaTelegram();

    tgPost($this, tgMensaje($this, ['photo' => [['file_id' => 'chica', 'width' => 90, 'height' => 30], ['file_id' => 'grande', 'width' => 1280, 'height' => 960, 'file_size' => strlen($this->archivo)]]]))->assertOk();

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/getFile') && $r->data()['file_id'] === 'grande');
    $b = DB::table('borradores_documento')->sole();
    $doc = DB::table('documentos')->sole();
    expect($b->estado)->toBe('para_revisar')->and($b->creado_por)->toBe($this->dueno->id)->and($doc->tipo_codigo)->toBe('vacunacion_aftosa')
        ->and(DB::table('documento_version_archivos')->value('archivo_sha256'))->toBe(hash('sha256', $this->archivo))
        ->and(FakeExtractor::$llamadas)->toHaveCount(1);
    $p = DB::table('telegram_propuestas')->sole();
    $dudosos = json_decode($p->datos, true)['dudosos'];
    expect($p->estado)->toBe('revisando')
        ->and($dudosos)->toContain('vacunador_nombre', 'categorias.1.vacunados_brucelosis', 'categorias.0.categoria_codigo')->not->toContain('renspa', 'numero_acta');
    $textos = tgEnviados();
    expect(implode("\n", $textos))->toContain('Esto es lo que entendí')->toContain('- RENSPA: 18.105.0.00001/00')->toContain('Fecha de vacunación: 12/05/2026')
        ->toContain('Tengo '.count($dudosos).' dudas');
    expect(end($textos))->toContain('Duda 1 de '.count($dudosos))->and(tgBotones())->toBe(["b:{$p->id}:ok", "b:{$p->id}:fix", "b:{$p->id}:nl"]);
    expect(DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->count())->toBe(0);

    // Respondemos de a una: la brucelosis de terneras se corrige escribiendo el número; el resto, correcto.
    foreach ($dudosos as $ruta) {
        if ($ruta === 'categorias.1.vacunados_brucelosis') {
            tgBoton($this, "b:{$p->id}:fix");
            expect(collect(tgEnviados())->last())->toContain('Escribime el valor correcto')->toContain('Escribí solo el número');
            tgTexto($this, 'dos');
            expect(collect(tgEnviados())->last())->toContain('No entendí el valor');
            tgTexto($this, '2');
        } else {
            tgBoton($this, "b:{$p->id}:ok");
        }
    }
    expect(collect(tgEnviados())->last())->toContain('Así queda')->toContain('¿Lo registro en La Esperanza?')
        ->and(tgBotones())->toBe(["b:{$p->id}:si", "b:{$p->id}:no"]);
    $correccion = DB::table('borrador_correcciones')->where('ruta', 'categorias.1.vacunados_brucelosis')->sole();
    expect($correccion->estado)->toBe('editado')->and($correccion->usuario_id)->toBe($this->dueno->id);
    expect(DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->count())->toBe(0); // nada sin confirmar

    tgBoton($this, "b:{$p->id}:si");
    $b = DB::table('borradores_documento')->sole();
    expect($b->estado)->toBe('confirmado')->and(DB::table('telegram_propuestas')->value('estado'))->toBe('confirmada');
    $vacs = DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->get();
    expect($vacs)->not->toBeEmpty()->and($vacs->pluck('origen')->unique()->all())->toBe(['telegram'])
        ->and(DB::table('borrador_correcciones')->where('borrador_id', $b->id)->where('estado', 'pendiente')->count())->toBe(0)
        ->and(json_decode($b->campos, true))->each(fn ($c) => $c->estado->not->toBe('pendiente'));
    expect(collect(tgEnviados())->last())->toContain('Listo: registré')
        ->and(DB::table('telegram_mensajes')->where('accion', 'borrador_confirmado')->count())->toBe(1);
});

it('documento: cancelar descarta el borrador sin registrar; un rol sin documentos.crear no puede mandar fotos', function () {
    vincular($this);
    FakeExtractor::$salida = actaTelegram();
    tgPost($this, tgMensaje($this, ['document' => ['file_id' => 'pdf1', 'mime_type' => 'application/pdf', 'file_size' => 5]]))->assertOk();
    $p = DB::table('telegram_propuestas')->sole();
    tgBoton($this, "b:{$p->id}:no");
    expect(DB::table('borradores_documento')->value('estado'))->toBe('descartado')->and(DB::table('eventos')->where('tipo', 'sanidad.vacunacion')->count())->toBe(0)
        ->and(DB::table('telegram_propuestas')->value('estado'))->toBe('cancelada');

    tgPost($this, tgMensaje($this, ['document' => ['file_id' => 'x', 'mime_type' => 'application/zip', 'file_size' => 5]]))->assertOk();
    expect(collect(tgEnviados())->last())->toContain('leo fotos');

    $lector = miembro($this->est, 'lectura');
    vincular($this, $lector, 8880004, 9004);
    tgPost($this, tgMensaje($this, ['photo' => [['file_id' => 'f', 'file_size' => 5]]], 8880004, 9004))->assertOk();
    expect(collect(tgEnviados())->last())->toContain('no permite cargar documentos')->and(DB::table('borradores_documento')->count())->toBe(1);
});

it('avisos del Notificador también salen por Telegram (incluido clima_alerta), respetando preferencias y la pausa del chat', function () {
    $v = vincular($this);
    $n = app(Notificador::class);
    Http::fake(); // limpia el registro (los stubs del beforeEach siguen)
    $n->aUsuario($this->dueno->id, $this->est->id, 'clima_alerta', 'Helada probable', 'Mínima de -2 °C el jueves.', [], 'clima|1');
    $sent = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values();
    expect($sent)->toHaveCount(1)->and($sent[0]['chat_id'])->toBe(TG_CHAT)->and($sent[0]['text'])->toBe("Helada probable · La Esperanza\nMínima de -2 °C el jueves.");
    expect(DB::table('telegram_mensajes')->where('accion', 'aviso_enviado')->value('entidad_id'))->toBe(DB::table('notificaciones')->value('id'));

    // Preferencia telegram=false para ese tipo → no sale (pero la notificación in-app sí).
    $this->putJson('/api/v1/me/preferencias-notificacion', [['tipo' => 'clima_alerta', 'push' => true, 'email' => false, 'resumen_diario' => false, 'telegram' => false]])->assertNoContent();
    $this->getJson('/api/v1/me/preferencias-notificacion')->assertJsonPath('data.0.telegram', false)->assertJsonPath('meta.defaults.telegram', true);
    $n->aUsuario($this->dueno->id, $this->est->id, 'clima_alerta', 'Calor', 'ITH alto.', [], 'clima|2');
    // Otro tipo sí, salvo que el chat tenga los avisos pausados.
    app(Vinculos::class)->avisos($v, false);
    $n->aUsuario($this->dueno->id, $this->est->id, 'tarea_sanitaria', 'Vacunar', 'Mañana.', [], 'tarea|1');
    expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/sendMessage'))->count())->toBe(1)
        ->and(DB::table('notificaciones')->count())->toBe(3);
});

it('el token del bot nunca aparece en errores; sin token el código de vinculación da 503', function () {
    Http::fake(['tg-falla.test/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
    $tg = new TelegramApi(TG_TOKEN, 'https://tg-falla.test');
    try {
        $tg->enviar(1, 'hola');
        $this->fail('Debía fallar');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('chat not found')->not->toContain(TG_TOKEN);
    }
    config(['tropilla.telegram.token' => null]);
    $this->postJson('/api/v1/me/telegram/codigo')->assertStatus(503)->assertJsonPath('code', 'no_disponible');
});

it('herramientas de solo lectura: animal por caravana, preñadas, vencimientos, clima y ofertas sobre las consultas del dominio', function () {
    Http::preventStrayRequests();
    $h = bufala($this);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.tacto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$h]],
        'payload' => ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 290], 'ocurrido_en' => '2026-10-16', 'confirmar_advertencias' => true])->assertCreated();
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Vacunar aftosa', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-10-30'])->assertCreated();
    DB::table('clima_estado')->insert(['establecimiento_id' => $this->est->id, 'precision' => 'departamento', 'zona' => 'Mercedes, Corrientes',
        'pronostico' => json_encode([['fecha' => '2026-10-17', 'tmin' => -1]]), 'errores' => '[]', 'calculado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('clima_alertas')->insert(['id' => (string) Str::uuid7(), 'establecimiento_id' => $this->est->id, 'regla_codigo' => 'helada', 'tipo' => 'helada', 'severidad' => 'alta',
        'titulo' => 'Helada probable', 'detalle' => 'Mínima -1 °C', 'recomendacion' => 'Reparo', 'fuente' => 'Open-Meteo', 'fuente_url' => 'https://open-meteo.com', 'zona' => 'Mercedes',
        'desde' => '2026-10-17', 'hasta' => '2026-10-17', 'horizonte_dias' => 1, 'estado' => 'activa', 'emitida_en' => now(), 'calculada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $ctx = Contexto::desdeVinculo(vincular($this));
    $h = fn (string $n, array $a = []) => Herramientas::ejecutar($ctx, $n, $a);

    expect($h('buscar_animal', ['identificacion' => '01234'])['animales'][0])->toMatchArray(['identificacion' => '1234', 'categoria' => 'Búfala', 'estado_reproductivo' => 'prenada'])
        ->and(collect($h('reproduccion')['tablero'])->firstWhere('especie', 'BUB')['prenadas'])->toBe(1)
        ->and($h('vencimientos', ['dias' => 15])['tareas_sanitarias'][0])->toMatchArray(['titulo' => 'Vacunar aftosa', 'vencida' => false])
        ->and($h('alertas_clima')['alertas'][0]['titulo'])->toBe('Helada probable')
        ->and($h('ofertas'))->toBe(['ofertas' => []])
        ->and($h('borrar_todo'))->toHaveKey('error');
});

it('álbum: varias fotos juntas son un documento de varias páginas; contacto propio, /avisos y /cancelar', function () {
    Queue::fake([CerrarAlbumJob::class]);
    vincular($this);
    FakeExtractor::$salida = actaTelegram();
    foreach (['pagina-1', 'pagina-2'] as $i => $bytes) {
        $this->archivo = $bytes;
        tgPost($this, tgMensaje($this, ['media_group_id' => 'album-9', 'photo' => [['file_id' => "f{$i}", 'file_size' => 8]]]))->assertOk();
    }
    $p = DB::table('telegram_propuestas')->sole();
    expect(json_decode($p->datos, true)['paginas'])->toHaveCount(2)->and(DB::table('documentos')->count())->toBe(0);
    Queue::assertPushed(CerrarAlbumJob::class, 1);
    (new CerrarAlbumJob($p->id))->handle(app(RecibirDocumento::class));
    expect(DB::table('documento_version_archivos')->count())->toBe(2)->and(FakeExtractor::$llamadas[0]['paginas'])->toBe(2)
        ->and(DB::table('telegram_propuestas')->value('estado'))->toBe('revisando');

    tgTexto($this, '/cancelar');
    expect(DB::table('telegram_propuestas')->value('estado'))->toBe('cancelada')->and(collect(tgEnviados())->last())->toContain('descarté lo pendiente');

    tgPost($this, tgMensaje($this, ['contact' => ['phone_number' => '+54 9 3773 123456', 'user_id' => 1]]))->assertOk();
    expect(DB::table('telegram_vinculos')->value('telefono'))->toBeNull();
    tgPost($this, tgMensaje($this, ['contact' => ['phone_number' => '+54 9 3773 123456', 'user_id' => 9001]]))->assertOk();
    expect(DB::table('telegram_vinculos')->value('telefono'))->toBe('+5493773123456');

    tgTexto($this, '/avisos');
    expect((bool) DB::table('telegram_vinculos')->value('avisos'))->toBeFalse();
    tgTexto($this, '/avisos');
    expect((bool) DB::table('telegram_vinculos')->value('avisos'))->toBeTrue();
});
