<?php

namespace App\Domain\Documentos\Borradores;

use App\Domain\Documentos\Borradores\Actions\ExtraerBorrador;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Lectura asíncrona de un borrador (202 + polling). Los reintentos de errores transitorios los maneja la Action. */
class ExtraerBorradorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Debe quedar por debajo de `retry_after` de la conexión (config/queue.php, 360 s) para que no se reentregue. */
    public int $timeout = 300;

    /** Si se pasa del timeout, se marca fallido en vez de volver a la cola. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $borradorId) {}

    public function handle(ExtraerBorrador $extraer): void
    {
        $extraer->handle($this->borradorId);
    }

    /** Si el worker lo corta (timeout) el borrador no puede quedar `extrayendo` para siempre: pasa a `fallido` y se relee a mano. */
    public function failed(?Throwable $e): void
    {
        DB::table('borradores_documento')->where('id', $this->borradorId)->where('estado', 'extrayendo')->update([
            'estado' => 'fallido', 'error' => __('La lectura tardó demasiado. Probá leerla de nuevo.'), 'updated_at' => now(),
        ]);
    }
}
