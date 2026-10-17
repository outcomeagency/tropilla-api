<?php

namespace App\Domain\Telegram\Documentos;

use App\Domain\Telegram\Contexto;
use App\Domain\Telegram\TelegramApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Sigue la lectura de un borrador mandado por Telegram (TPA-77) sin tocar el flujo de TPA-61/62: mira el estado cada
 * 10 s (hasta ~15 min). `para_revisar` → arranca la revisión por chat; `fallido` → avisa y deja el documento guardado.
 */
class SeguirBorradorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_INTENTOS = 90;

    public int $tries = 1;

    public function __construct(public readonly string $propuestaId, public readonly int $intento = 1) {}

    public function handle(TelegramApi $tg, RevisionBorrador $revision): void
    {
        $p = DB::table('telegram_propuestas')->where('id', $this->propuestaId)->first();
        if ($p === null || $p->estado !== 'leyendo' || $p->borrador_id === null) {
            return;
        }
        $vinculo = DB::table('telegram_vinculos')->where('id', $p->vinculo_id)->whereNull('desvinculado_en')->first();
        $ctx = $vinculo ? Contexto::desdeVinculo($vinculo, $p->establecimiento_id) : null;
        $b = DB::table('borradores_documento')->where('id', $p->borrador_id)->first();
        if ($ctx === null || $ctx->est === null || $b === null) {
            DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'cancelada', 'updated_at' => now()]);

            return;
        }
        match ($b->estado) {
            'para_revisar' => Contexto::como($ctx->usuario, fn () => $revision->iniciar($ctx, $p)),
            'fallido' => $this->fallido($tg, $ctx, $p, $b),
            'confirmado', 'descartado' => DB::table('telegram_propuestas')->where('id', $p->id)
                ->update(['estado' => $b->estado === 'confirmado' ? 'confirmada' : 'cancelada', 'updated_at' => now()]),
            default => $this->intento < self::MAX_INTENTOS
                ? self::dispatch($this->propuestaId, $this->intento + 1)->delay(now()->addSeconds(10))
                : $tg->enviar($ctx->chatId, 'La lectura está tardando más de lo normal. El documento quedó guardado: revisalo desde la app o el panel (Documentos).'),
        };
    }

    private function fallido(TelegramApi $tg, Contexto $ctx, object $p, object $b): void
    {
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'fallida', 'updated_at' => now()]);
        $tg->enviar($ctx->chatId, 'No pude leer el documento'.($b->error ? ': '.$b->error : '.').' Quedó guardado en Documentos: podés reintentar la lectura o cargarlo a mano desde la app o el panel.');
        $ctx->bitacora('saliente', 'documento', 'lectura_fallida', 'borrador_documento', $b->id);
    }
}
