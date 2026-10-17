<?php

namespace App\Domain\Telegram\Documentos;

use App\Domain\Telegram\Contexto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Unos segundos después de la primera foto de un álbum: crea el documento con todas sus páginas (TPA-77). */
class CerrarAlbumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $propuestaId) {}

    public function handle(RecibirDocumento $recibir): void
    {
        $p = DB::transaction(function () {
            $p = DB::table('telegram_propuestas')->where('id', $this->propuestaId)->lockForUpdate()->first();
            if ($p === null || $p->estado !== 'pendiente' || $p->tipo !== 'borrador') {
                return null;
            }
            DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'leyendo', 'updated_at' => now()]);

            return $p;
        });
        $vinculo = $p ? DB::table('telegram_vinculos')->where('id', $p->vinculo_id)->whereNull('desvinculado_en')->first() : null;
        $ctx = $vinculo ? Contexto::desdeVinculo($vinculo, $p->establecimiento_id) : null;
        if ($ctx === null || $ctx->est === null) {
            $p && DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'cancelada', 'updated_at' => now()]);

            return;
        }
        Contexto::como($ctx->usuario, fn () => $recibir->cerrarAlbum($ctx, $p));
    }
}
