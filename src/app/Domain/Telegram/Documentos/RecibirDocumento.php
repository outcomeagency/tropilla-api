<?php

namespace App\Domain\Telegram\Documentos;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Documentos\Borradores\Actions\CrearBorrador;
use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Telegram\Contexto;
use App\Domain\Telegram\TelegramApi;
use App\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Foto o PDF por Telegram → el mismo borrador de TPA-61/62 (TPA-77): el original se guarda direccionado por contenido
 * (`GuardarArchivo`), se registra el documento por la única puerta (`RegistrarDocumentoVersion`) y se pide la lectura con
 * `CrearBorrador`. Un álbum (varias fotos juntas, `media_group_id`) es un documento de varias páginas. Cuando la lectura
 * termina, `SeguirBorradorJob` arranca la revisión por chat (`RevisionBorrador`).
 */
final class RecibirDocumento
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(
        private readonly TelegramApi $tg,
        private readonly GuardarArchivo $guardar,
        private readonly RegistrarDocumentoVersion $registrarDocumento,
        private readonly CrearBorrador $crearBorrador,
    ) {}

    /** @param array<string, mixed> $m mensaje de Telegram con `photo` o `document` */
    public function handle(Contexto $ctx, array $m): void
    {
        if (! $ctx->puede('documentos.crear')) {
            $this->tg->enviar($ctx->chatId, "Tu rol en {$ctx->est->nombre} no permite cargar documentos.");

            return;
        }
        if (isset($m['photo'])) {
            $foto = end($m['photo']); // la de mayor resolución
            [$fileId, $mime, $tam] = [$foto['file_id'], 'image/jpeg', (int) ($foto['file_size'] ?? 0)];
        } else {
            [$fileId, $mime, $tam] = [$m['document']['file_id'], strtolower((string) ($m['document']['mime_type'] ?? '')), (int) ($m['document']['file_size'] ?? 0)];
            if (! in_array($mime, self::MIMES, true)) {
                $this->tg->enviar($ctx->chatId, 'Por ahora leo fotos (JPG, PNG, WebP) y PDF.');

                return;
            }
        }
        $max = (int) config('tropilla.telegram.max_archivo_mb') * 1024 * 1024;
        if ($tam > $max) {
            $this->tg->enviar($ctx->chatId, 'El archivo es demasiado grande (máximo '.config('tropilla.telegram.max_archivo_mb').' MB).');

            return;
        }
        try {
            $bytes = $this->tg->descargar($fileId, $max)['bytes'];
        } catch (RuntimeException $e) {
            report($e);
            $this->tg->enviar($ctx->chatId, 'No pude descargar el archivo de Telegram. Probá mandarlo de nuevo.');

            return;
        }
        $pagina = ['sha256' => $this->guardar->handle($bytes, $mime, $ctx->usuario), 'bytes' => strlen($bytes), 'mime' => $mime];

        $grupo = $m['media_group_id'] ?? null;
        if ($grupo === null) {
            $this->crear($ctx, [$pagina]);

            return;
        }
        // Álbum: Telegram manda una foto por update. Se juntan y se cierran unos segundos después de la primera.
        Cache::lock("tg-album-{$ctx->chatId}-{$grupo}", 10)->block(5, fn () => DB::transaction(function () use ($ctx, $grupo, $pagina) {
            $p = DB::table('telegram_propuestas')->where('vinculo_id', $ctx->vinculo->id)->where('tipo', 'borrador')->where('estado', 'pendiente')
                ->whereRaw("datos->>'media_group_id' = ?", [(string) $grupo])->lockForUpdate()->first();
            if ($p !== null) {
                $d = json_decode($p->datos, true);
                $d['paginas'][] = $pagina;
                DB::table('telegram_propuestas')->where('id', $p->id)->update(['datos' => json_encode($d), 'updated_at' => now()]);

                return;
            }
            $id = (string) Str::uuid7();
            DB::table('telegram_propuestas')->insert([
                'id' => $id, 'vinculo_id' => $ctx->vinculo->id, 'usuario_id' => $ctx->usuario->id, 'establecimiento_id' => $ctx->est->id, 'tipo' => 'borrador',
                'estado' => 'pendiente', 'datos' => json_encode(['media_group_id' => (string) $grupo, 'paginas' => [$pagina]]),
                'expira_en' => now()->addHours((int) config('tropilla.telegram.propuesta_horas')), 'created_at' => now(), 'updated_at' => now(),
            ]);
            CerrarAlbumJob::dispatch($id)->delay(now()->addSeconds(4))->afterCommit();
        }));
    }

    /** Cierra un álbum: crea el documento con todas sus páginas. */
    public function cerrarAlbum(Contexto $ctx, object $p): void
    {
        $d = json_decode($p->datos, true);
        $this->crear($ctx, $d['paginas'], $p->id);
    }

    /** @param list<array{sha256: string, bytes: int, mime: string}> $paginas */
    public function crear(Contexto $ctx, array $paginas, ?string $propuestaId = null): void
    {
        $paginas = array_values(array_unique($paginas, SORT_REGULAR));
        if (count($paginas) > CrearBorrador::MAX_PAGINAS) {
            $this->tg->enviar($ctx->chatId, 'Leo hasta '.CrearBorrador::MAX_PAGINAS.' páginas por documento. Mandalo en partes.');
            $propuestaId && DB::table('telegram_propuestas')->where('id', $propuestaId)->update(['estado' => 'cancelada', 'updated_at' => now()]);

            return;
        }
        $codigo = array_key_first(Familias::TODAS); // hoy la única familia extraíble es el acta de vacunación
        $familia = Familias::get($codigo);
        $tipoNombre = Catalogos::nombre(DB::table('tipos_documento')->where('codigo', $familia->tipoDocumento())->value('nombres'), 'es') ?? 'Documento';
        try {
            $doc = $this->registrarDocumento->handle($ctx->est, $ctx->usuario, ArmarSobreDocumentoWeb::alta($ctx->est, $ctx->usuario, [
                'tipo' => $familia->tipoDocumento(), 'titulo' => mb_substr($tipoNombre.' · Telegram '.Carbon::now($ctx->est->zona_horaria)->format('d/m/Y H:i'), 0, 200),
                'archivos' => $paginas,
            ]), ['canal' => 'web']);
            [$b] = $this->crearBorrador->handle($ctx->est, $ctx->usuario, ['id' => (string) Str::uuid7(), 'documento_id' => $doc->documentoId, 'familia' => $codigo], null);
        } catch (ApiException|EventoRechazado $e) {
            $this->tg->enviar($ctx->chatId, 'No pude guardar el documento: '.$e->getMessage());
            $propuestaId && DB::table('telegram_propuestas')->where('id', $propuestaId)->update(['estado' => 'fallida', 'updated_at' => now()]);

            return;
        }

        $datos = ['documento_id' => $doc->documentoId, 'paginas' => count($paginas), 'familia' => $codigo];
        if ($propuestaId === null) {
            $propuestaId = (string) Str::uuid7();
            DB::table('telegram_propuestas')->insert([
                'id' => $propuestaId, 'vinculo_id' => $ctx->vinculo->id, 'usuario_id' => $ctx->usuario->id, 'establecimiento_id' => $ctx->est->id, 'tipo' => 'borrador',
                'estado' => 'leyendo', 'datos' => json_encode($datos), 'borrador_id' => $b->id,
                'expira_en' => now()->addHours((int) config('tropilla.telegram.propuesta_horas')), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('telegram_propuestas')->where('id', $propuestaId)->update(['estado' => 'leyendo', 'borrador_id' => $b->id, 'datos' => json_encode($datos), 'updated_at' => now()]);
        }
        $n = count($paginas);
        $this->tg->enviar($ctx->chatId, 'Recibí '.($n === 1 ? 'la página' : "{$n} páginas")." en {$ctx->est->nombre}. La estoy leyendo como {$tipoNombre}; te aviso cuando termine. "
            .'No se registra nada hasta que lo confirmes.');
        $ctx->bitacora('entrante', 'documento', 'documento_recibido', 'borrador_documento', $b->id, ['paginas' => $n, 'mimes' => array_values(array_unique(array_column($paginas, 'mime')))]);
        SeguirBorradorJob::dispatch($propuestaId)->delay(now()->addSeconds(8));
    }
}
