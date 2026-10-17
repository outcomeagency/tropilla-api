<?php

namespace App\Http\Controllers\Api\V1\Telegram;

use App\Domain\Telegram\ProcesarUpdateJob;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Webhook del bot (TPA-77). Telegram manda el `secret_token` de `setWebhook` en `X-Telegram-Bot-Api-Secret-Token`: se
 * compara en tiempo constante. Sin token o secreto configurados el endpoint no existe (404). Idempotente por `update_id`
 * (Telegram reintenta). El procesamiento va a la cola: acá solo se valida y se encola.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secreto = (string) config('tropilla.telegram.webhook_secret');
        if ($secreto === '' || (string) config('tropilla.telegram.token') === '') {
            throw ApiException::noEncontrado();
        }
        if (! hash_equals($secreto, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            throw ApiException::sinPermiso();
        }
        $update = $request->json()->all();
        if (! is_int($update['update_id'] ?? null)) {
            return response()->json(['ok' => true]);
        }
        $nuevo = DB::table('telegram_updates')->insertOrIgnore(['update_id' => $update['update_id'], 'recibido_en' => now()]);
        if ($nuevo === 1) {
            ProcesarUpdateJob::dispatch(array_intersect_key($update, array_flip(['update_id', 'message', 'callback_query'])));
        }

        return response()->json(['ok' => true]);
    }
}
