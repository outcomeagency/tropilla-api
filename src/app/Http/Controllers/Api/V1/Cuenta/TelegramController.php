<?php

namespace App\Http\Controllers\Api\V1\Cuenta;

use App\Domain\Telegram\Bitacora;
use App\Domain\Telegram\TelegramApi;
use App\Domain\Telegram\Vinculos;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vínculo con el bot de Telegram del usuario autenticado (TPA-77): estado, código de vinculación de un solo uso,
 * avisos on/off y desvincular. El código solo se devuelve al crearlo (en la base queda su hash).
 */
class TelegramController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::estado($request->user()->id)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function codigo(Request $request, Vinculos $vinculos): JsonResponse
    {
        if (! TelegramApi::desdeConfig()->configurado()) {
            throw new ApiException(503, 'no_disponible', __('El bot de Telegram no está disponible en este servidor.'));
        }
        $c = $vinculos->generarCodigo($request->user());

        return response()->json(['data' => [
            'codigo' => $c['codigo'],
            'url' => 'https://t.me/'.config('tropilla.telegram.bot_username').'?start='.$c['codigo'],
            'expira_en' => Formato::fechaHora($c['expira_en']),
        ]], 201);
    }

    public function update(Request $request, Vinculos $vinculos): JsonResponse
    {
        $d = $request->validate(['avisos' => ['required', 'boolean']]);
        $v = Vinculos::deUsuario($request->user()->id) ?? throw ApiException::noEncontrado();
        if ((bool) $v->avisos !== (bool) $d['avisos']) {
            $vinculos->avisos($v, (bool) $d['avisos']);
        }

        return response()->json(['data' => self::estado($request->user()->id)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Vinculos $vinculos): JsonResponse
    {
        $v = Vinculos::deUsuario($request->user()->id);
        if ($v !== null) {
            $vinculos->desvincular($v, 'usuario_panel');
            Bitacora::registrar((int) $v->chat_id, 'saliente', 'sistema', $v, 'desvinculado_panel');
            $chat = (int) $v->chat_id;
            dispatch(function () use ($chat) {
                try {
                    app(TelegramApi::class)->enviar($chat, 'Este chat se desvinculó de TROPILLA desde tu cuenta. Para volver, generá un código nuevo.', ['remove_keyboard' => true]);
                } catch (Throwable) {
                }
            });
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> TelegramVinculo */
    public static function estado(string $usuarioId): array
    {
        $v = Vinculos::deUsuario($usuarioId);
        $bot = (string) config('tropilla.telegram.bot_username');
        $est = $v?->establecimiento_id ? DB::table('establecimientos')->where('id', $v->establecimiento_id)->first(['id', 'nombre']) : null;

        return [
            'disponible' => TelegramApi::desdeConfig()->configurado(),
            'bot' => ['username' => $bot, 'url' => "https://t.me/{$bot}"],
            'vinculado' => $v !== null,
            'username' => $v?->username,
            'telefono' => $v?->telefono,
            'avisos' => $v !== null ? (bool) $v->avisos : null,
            'vinculado_en' => $v ? Formato::fechaHora(Carbon::parse($v->vinculado_en)) : null,
            'ultimo_mensaje_en' => $v?->ultimo_mensaje_en ? Formato::fechaHora(Carbon::parse($v->ultimo_mensaje_en)) : null,
            'establecimiento' => $est ? ['id' => $est->id, 'nombre' => $est->nombre] : null,
        ];
    }
}
