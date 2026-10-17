<?php

namespace App\Domain\Telegram;

use App\Domain\Telegram\Asistente\Asistente;
use App\Domain\Telegram\Asistente\CargaRapida;
use App\Domain\Telegram\Documentos\RecibirDocumento;
use App\Domain\Telegram\Documentos\RevisionBorrador;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Atiende un update de Telegram (TPA-77; lo despacha `ProcesarUpdateJob`). Solo chats privados. Sin vínculo solo se
 * acepta `/start <código>`. Con vínculo: comandos (`/campo`, `/salir`, `/avisos`, `/cancelar`, `/ayuda`), contacto,
 * fotos y PDF (borrador TPA-61/62), audios y texto (asistente) y botones (callback_data `c:` campo, `e:` evento, `b:` borrador).
 */
final class Bot
{
    public const AYUDA = "Puedo ayudarte con tu campo desde acá:\n"
        ."- Mandame una foto o PDF de un acta de vacunación y la leo; te pregunto solo lo dudoso y registro cuando confirmes.\n"
        ."- Preguntame: «¿cuántas vacas preñadas tengo?», «¿qué vence este mes?», «¿hay alertas de clima?», «¿qué dice la caravana 1234?».\n"
        ."- Contame una carga simple, por texto o audio: «pesé la 1234, 380 kilos», «nació una ternera de la 552», «murió la 77», «traté a la 1234 por queratitis con oxitetraciclina».\n"
        ."  Siempre te pido confirmación antes de registrar.\n\n"
        .'/campo elegir el establecimiento · /avisos activar o pausar avisos · /cancelar descartar lo pendiente · /salir desvincular este chat';

    public function __construct(
        private readonly TelegramApi $tg,
        private readonly Vinculos $vinculos,
        private readonly Asistente $asistente,
        private readonly CargaRapida $carga,
        private readonly RecibirDocumento $documentos,
        private readonly RevisionBorrador $revision,
    ) {}

    /** @param array<string, mixed> $u */
    public function atender(array $u): void
    {
        if (isset($u['callback_query'])) {
            $this->callback($u['callback_query']);
        } elseif (isset($u['message'])) {
            $this->mensaje($u['message']);
        }
    }

    /** @param array<string, mixed> $m */
    private function mensaje(array $m): void
    {
        if (($m['chat']['type'] ?? null) !== 'private' || ! isset($m['from']['id'])) {
            return;
        }
        $chatId = (int) $m['chat']['id'];
        $texto = trim((string) ($m['text'] ?? ''));
        [$comando, $arg] = str_starts_with($texto, '/') ? array_pad(explode(' ', preg_replace('/@\w+/', '', $texto, 1), 2), 2, '') : [null, ''];

        $vinculo = Vinculos::deChat($chatId);
        if ($comando === '/start' && trim($arg) !== '') {
            $this->start($chatId, $m['from'], trim($arg), $vinculo);

            return;
        }
        if ($vinculo === null) {
            Bitacora::registrar($chatId, 'entrante', $comando ? 'comando' : self::tipo($m), null, 'sin_vinculo', detalle: array_filter(['comando' => $comando]));
            $this->tg->enviar($chatId, 'Hola, soy el bot de TROPILLA. Para usarlo, vinculá tu cuenta: en la app (Más → Bot de Telegram) o en el panel '
                .'(Configuración → Bot de Telegram) tocá «Vincular» y abrí el enlace, o mandame /start seguido del código.');

            return;
        }
        $ctx = Contexto::desdeVinculo($vinculo);
        if ($ctx === null) {
            $this->vinculos->desvincular($vinculo, 'sistema');
            $this->tg->enviar($chatId, 'Tu cuenta no está habilitada. Desvinculé este chat.');

            return;
        }
        DB::table('telegram_vinculos')->where('id', $vinculo->id)->update(['ultimo_mensaje_en' => now()]);
        Contexto::como($ctx->usuario, fn () => $this->conVinculo($ctx, $m, $comando, $texto));
    }

    /** @param array<string, mixed> $m */
    private function conVinculo(Contexto $ctx, array $m, ?string $comando, string $texto): void
    {
        if ($comando !== null) {
            $ctx->bitacora('entrante', 'comando', ltrim($comando, '/'));
            match ($comando) {
                '/start', '/ayuda', '/help' => $this->tg->enviar($ctx->chatId, "Hola {$ctx->usuario->nombre}. ".self::AYUDA),
                '/salir' => $this->salir($ctx),
                '/campo' => $this->campos($ctx),
                '/avisos' => $this->avisos($ctx),
                '/cancelar' => $this->cancelar($ctx),
                default => $this->tg->enviar($ctx->chatId, 'No conozco ese comando. '.self::AYUDA),
            };

            return;
        }
        if (isset($m['contact'])) {
            $this->contacto($ctx, $m);

            return;
        }
        if ($ctx->est === null) {
            $this->campos($ctx, 'Primero elegí con qué establecimiento trabajamos.');

            return;
        }
        if (isset($m['photo']) || isset($m['document'])) {
            $this->documentos->handle($ctx, $m);

            return;
        }
        if (isset($m['voice']) || isset($m['audio'])) {
            $a = $m['voice'] ?? $m['audio'];
            if (($a['file_size'] ?? 0) > 10 * 1024 * 1024 || ($a['duration'] ?? 0) > 300) {
                $this->tg->enviar($ctx->chatId, 'El audio es muy largo: mandame uno de hasta 5 minutos.');

                return;
            }
            try {
                $bytes = $this->tg->descargar($a['file_id'], 10 * 1024 * 1024)['bytes'];
            } catch (RuntimeException $e) {
                report($e);
                $this->tg->enviar($ctx->chatId, 'No pude descargar el audio. Probá de nuevo.');

                return;
            }
            $mime = strtolower((string) ($a['mime_type'] ?? 'audio/ogg'));
            $this->asistente->audio($ctx, $bytes, str_starts_with($mime, 'audio/') ? $mime : 'audio/ogg');

            return;
        }
        if ($texto === '') {
            $this->tg->enviar($ctx->chatId, 'Por ahora entiendo texto, audios, fotos y PDF.');

            return;
        }
        if ($p = RevisionBorrador::esperandoTexto($ctx->vinculo)) {
            $this->revision->valorEscrito($ctx, $p, $texto);

            return;
        }
        $this->asistente->responder($ctx, $texto);
    }

    /** @param array<string, mixed> $cq */
    private function callback(array $cq): void
    {
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);
        $this->tg->responderCallback((string) $cq['id']);
        $vinculo = $chatId ? Vinculos::deChat($chatId) : null;
        if ($vinculo === null || (int) $vinculo->telegram_usuario_id !== (int) ($cq['from']['id'] ?? 0)) {
            return;
        }
        $partes = explode(':', (string) ($cq['data'] ?? ''), 4);
        $mensajeId = isset($cq['message']['message_id']) ? (int) $cq['message']['message_id'] : null;
        $id = $partes[1] ?? '';
        if (! Str::isUuid($id)) {
            return;
        }
        if ($partes[0] === 'c') {
            $this->elegirCampo($vinculo, $id, $mensajeId);

            return;
        }
        $p = DB::table('telegram_propuestas')->where('id', $id)->where('vinculo_id', $vinculo->id)->first(['establecimiento_id']);
        $ctx = $p ? Contexto::desdeVinculo($vinculo, $p->establecimiento_id) : null;
        if ($ctx === null || $ctx->est === null) {
            $this->tg->enviar($chatId, 'Esa propuesta ya no está disponible.');

            return;
        }
        Contexto::como($ctx->usuario, fn () => match ($partes[0]) {
            'e' => $this->carga->responder($ctx, $id, $partes[2] ?? '', $mensajeId),
            'b' => $this->revision->responder($ctx, $id, $partes[2] ?? '', $partes[3] ?? null, $mensajeId),
            default => null,
        });
    }

    /** @param array{id: int, username?: string} $de */
    private function start(int $chatId, array $de, string $codigo, ?object $actual): void
    {
        $intentos = 'tg-start:'.$chatId;
        if (RateLimiter::tooManyAttempts($intentos, 5)) {
            $this->tg->enviar($chatId, 'Demasiados intentos. Esperá unos minutos y generá un código nuevo.');

            return;
        }
        $v = $this->vinculos->canjear($codigo, $chatId, $de);
        if ($v === null) {
            RateLimiter::hit($intentos, 600);
            Bitacora::registrar($chatId, 'entrante', 'comando', $actual, 'vinculo_rechazado');
            $this->tg->enviar($chatId, 'El código no es válido o venció. Generá uno nuevo desde la app o el panel (dura '.config('tropilla.telegram.codigo_minutos').' minutos).');

            return;
        }
        Bitacora::registrar($chatId, 'entrante', 'comando', $v, 'vinculado', 'telegram_vinculo', $v->id);
        $usuario = User::query()->find($v->usuario_id);
        $this->tg->enviar($chatId, "Listo, {$usuario->nombre}: este chat quedó vinculado a tu cuenta de TROPILLA. Actúo con los permisos de tu usuario.\n\n".self::AYUDA,
            ['keyboard' => [[['text' => 'Compartir mi teléfono (opcional)', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true]);
        $ctx = Contexto::desdeVinculo($v);
        if ($ctx !== null && $ctx->est === null) {
            $this->campos($ctx, 'Tenés más de un establecimiento: elegí con cuál trabajamos.');
        }
    }

    private function salir(Contexto $ctx): void
    {
        $this->vinculos->desvincular($ctx->vinculo, 'usuario_bot');
        $this->tg->enviar($ctx->chatId, 'Listo, desvinculé este chat. No vas a recibir más avisos acá. Para volver, generá un código nuevo en la app o el panel.',
            ['remove_keyboard' => true]);
    }

    private function campos(Contexto $ctx, ?string $intro = null): void
    {
        $ests = Vinculos::establecimientos($ctx->usuario->id);
        if ($ests->isEmpty()) {
            $this->tg->enviar($ctx->chatId, 'Tu usuario no tiene establecimientos activos en TROPILLA.');

            return;
        }
        $filas = $ests->take(20)->map(fn ($e) => [[($e->id === $ctx->est?->id ? '· ' : '').mb_substr($e->nombre, 0, 40), "c:{$e->id}"]])->values()->all();
        $this->tg->enviar($ctx->chatId, ($intro ?? 'Elegí el establecimiento activo:').($ctx->est ? "\nAhora: {$ctx->est->nombre}." : ''), TelegramApi::botones($filas));
    }

    private function elegirCampo(object $vinculo, string $estId, ?int $mensajeId): void
    {
        if (! $this->vinculos->elegirEstablecimiento($vinculo, $estId)) {
            $this->tg->enviar((int) $vinculo->chat_id, 'No tenés acceso a ese establecimiento.');

            return;
        }
        $nombre = DB::table('establecimientos')->where('id', $estId)->value('nombre');
        Bitacora::registrar((int) $vinculo->chat_id, 'entrante', 'callback', $vinculo, 'establecimiento_activo', 'establecimiento', $estId);
        $texto = "Trabajamos con {$nombre}.";
        $mensajeId ? $this->tg->editar((int) $vinculo->chat_id, $mensajeId, $texto) : $this->tg->enviar((int) $vinculo->chat_id, $texto);
    }

    private function avisos(Contexto $ctx): void
    {
        $activos = ! (bool) $ctx->vinculo->avisos;
        $this->vinculos->avisos($ctx->vinculo, $activos);
        $this->tg->enviar($ctx->chatId, $activos
            ? 'Avisos activados: te mando por acá los avisos de TROPILLA (los que tengas habilitados en tus preferencias).'
            : 'Avisos pausados: no te mando más avisos por acá. Los seguís viendo en la app. Mandá /avisos para volver a activarlos.');
    }

    private function cancelar(Contexto $ctx): void
    {
        $n = DB::table('telegram_propuestas')->where('vinculo_id', $ctx->vinculo->id)->whereIn('estado', ['pendiente', 'revisando'])
            ->update(['estado' => 'cancelada', 'updated_at' => now()]);
        $this->tg->enviar($ctx->chatId, $n > 0 ? 'Listo, descarté lo pendiente. No se registró nada. Los documentos que mandaste quedan guardados en Documentos.' : 'No hay nada pendiente.');
    }

    /** @param array<string, mixed> $m */
    private function contacto(Contexto $ctx, array $m): void
    {
        if ((int) ($m['contact']['user_id'] ?? 0) !== (int) $m['from']['id']) {
            $this->tg->enviar($ctx->chatId, 'Solo guardo tu propio teléfono (con el botón «Compartir mi teléfono»).', ['remove_keyboard' => true]);

            return;
        }
        $this->vinculos->guardarTelefono($ctx->vinculo, (string) ($m['contact']['phone_number'] ?? ''));
        $ctx->bitacora('entrante', 'contacto', 'telefono_guardado');
        $this->tg->enviar($ctx->chatId, 'Gracias, guardé tu teléfono como referencia del vínculo.', ['remove_keyboard' => true]);
    }

    /** @param array<string, mixed> $m */
    private static function tipo(array $m): string
    {
        return match (true) {
            isset($m['photo']) => 'foto', isset($m['document']) => 'documento', isset($m['voice']) || isset($m['audio']) => 'audio',
            isset($m['contact']) => 'contacto', default => 'texto',
        };
    }
}
