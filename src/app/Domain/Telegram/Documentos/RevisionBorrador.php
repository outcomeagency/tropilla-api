<?php

namespace App\Domain\Telegram\Documentos;

use App\Domain\Documentos\Borradores\Actions\ConfirmarBorrador;
use App\Domain\Documentos\Borradores\Actions\RevisarCampos;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Telegram\Contexto;
use App\Domain\Telegram\TelegramApi;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Revisión por chat de un borrador de TPA-61/62 (TPA-77). Manda un resumen de lo leído y pregunta **solo** por los campos
 * dudosos (baja confianza, lecturas alternativas, sugerencias de TROPILLA, críticos vacíos o con advertencia) con botones
 * Correcto / Corregir / No se lee. Cada respuesta pasa por `RevisarCampos` (queda en `borrador_correcciones`: quién, cuándo,
 * antes y después). Al final pide confirmación explícita: recién ahí los campos que no se preguntaron se marcan como
 * revisados (el usuario los vio en el resumen) y se confirma con `ConfirmarBorrador` (eventos con origen `telegram`).
 */
final class RevisionBorrador
{
    public const UMBRAL = 0.85;

    public function __construct(
        private readonly TelegramApi $tg,
        private readonly RevisarCampos $revisar,
        private readonly ConfirmarBorrador $confirmar,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function iniciar(Contexto $ctx, object $p): void
    {
        $b = $this->borrador($p);
        $campos = json_decode($b->campos, true);
        $dudosos = self::dudosos($campos, json_decode($b->advertencias, true));
        $datos = array_merge(json_decode($p->datos, true), ['dudosos' => $dudosos, 'indice' => 0, 'esperando' => null]);
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'revisando', 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        $n = count($dudosos);
        $this->tg->enviar($ctx->chatId, "Terminé de leer el documento. Esto es lo que entendí:\n\n".self::resumen($campos)."\n\n"
            .($n === 0 ? 'No tengo dudas sobre lo leído.' : ($n === 1 ? 'Tengo 1 duda: te la pregunto.' : "Tengo {$n} dudas: te las pregunto de a una.")));
        $ctx->bitacora('saliente', 'documento', 'lectura_lista', 'borrador_documento', $b->id, ['campos' => count($campos), 'dudosos' => $n]);
        $this->preguntar($ctx, $p->id);
    }

    /** Botón: ok | nl | fix | v:<idx> | si | forzar | no. */
    public function responder(Contexto $ctx, string $propuestaId, string $accion, ?string $arg, ?int $mensajeId): void
    {
        $lock = Cache::lock('tg-propuesta-'.$propuestaId, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $p = DB::table('telegram_propuestas')->where('id', $propuestaId)->where('vinculo_id', $ctx->vinculo->id)->where('tipo', 'borrador')->first();
            if ($p === null || $p->estado !== 'revisando') {
                $this->tg->enviar($ctx->chatId, 'Ese documento ya no está en revisión.');

                return;
            }
            $datos = json_decode($p->datos, true);
            $ruta = $datos['dudosos'][$datos['indice']] ?? null;
            match ($accion) {
                'ok' => $ruta && $this->decidir($ctx, $p, $ruta, 'correcto', null, $mensajeId),
                'nl' => $ruta && $this->decidir($ctx, $p, $ruta, 'no_legible', null, $mensajeId),
                'fix' => $ruta && $this->pedirValor($ctx, $p, $ruta, $mensajeId),
                'v' => $ruta && $this->opcion($ctx, $p, $ruta, (int) $arg, $mensajeId),
                'si', 'forzar' => $this->confirmar($ctx, $p, $accion === 'forzar', $mensajeId),
                'no' => $this->descartar($ctx, $p, $mensajeId),
                default => null,
            };
        } finally {
            $lock->release();
        }
    }

    /** Propuesta en revisión esperando un valor escrito, si la hay. */
    public static function esperandoTexto(object $vinculo): ?object
    {
        return DB::table('telegram_propuestas')->where('vinculo_id', $vinculo->id)->where('tipo', 'borrador')->where('estado', 'revisando')
            ->whereRaw("datos->>'esperando' IS NOT NULL")->orderByDesc('updated_at')->first();
    }

    /** El usuario escribió el valor corregido. */
    public function valorEscrito(Contexto $ctx, object $p, string $texto): void
    {
        $datos = json_decode($p->datos, true);
        $ruta = $datos['esperando'];
        $campo = self::campo(json_decode($this->borrador($p)->campos, true), $ruta);
        if ($campo === null) {
            return;
        }
        $valor = self::interpretar($campo['tipo'], $texto);
        if ($valor === false) {
            $this->tg->enviar($ctx->chatId, 'No entendí el valor. '.self::formato($campo['tipo']));

            return;
        }
        try {
            $this->decidir($ctx, $p, $ruta, 'editado', $valor, null);
        } catch (ApiException $e) {
            $this->tg->enviar($ctx->chatId, $e->getMessage().' '.self::formato($campo['tipo']));
        }
    }

    private function preguntar(Contexto $ctx, string $propuestaId): void
    {
        $p = DB::table('telegram_propuestas')->where('id', $propuestaId)->first();
        $datos = json_decode($p->datos, true);
        $b = $this->borrador($p);
        $campos = json_decode($b->campos, true);
        while (($ruta = $datos['dudosos'][$datos['indice']] ?? null) !== null && self::campo($campos, $ruta) === null) {
            $datos['indice']++;
        }
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        if ($ruta === null) {
            $this->final($ctx, $p->id);

            return;
        }
        $c = self::campo($campos, $ruta);
        $adv = array_values(array_filter(json_decode($b->advertencias, true), fn ($a) => ($a['ruta'] ?? null) === $ruta));
        $texto = 'Duda '.($datos['indice'] + 1).' de '.count($datos['dudosos']).' · '.self::etiqueta($c)."\n"
            .'Leí: '.self::mostrar($c).($c['valor_extraido'] !== null && $c['valor'] === null ? ' (escrito: «'.$c['valor_extraido'].'»)' : '')
            .($c['alternativas'] !== [] ? "\nTambién podría ser: ".implode(' / ', $c['alternativas']) : '')
            .($c['sugerido'] ? "\n(Es una sugerencia de TROPILLA, no está escrito así en el papel.)" : '')
            .($c['pagina'] ? "\nPágina {$c['pagina']}." : '')
            .implode('', array_map(fn ($a) => "\nOjo: {$a['mensaje']}", $adv));
        $this->tg->enviar($ctx->chatId, $texto, TelegramApi::botones([[['Correcto', "b:{$p->id}:ok"], ['Corregir', "b:{$p->id}:fix"], ['No se lee', "b:{$p->id}:nl"]]]));
    }

    private function decidir(Contexto $ctx, object $p, string $ruta, string $estado, mixed $valor, ?int $mensajeId): bool
    {
        $b = $this->borrador($p);
        $cambio = ['ruta' => $ruta, 'estado' => $estado] + ($estado === 'editado' ? ['valor' => $valor] : []);
        $b = $this->revisar->handle($ctx->est, $ctx->usuario, $b, [$cambio], null, null);
        $c = self::campo(json_decode($b->campos, true), $ruta);
        $datos = json_decode($p->datos, true);
        $datos['indice']++;
        $datos['esperando'] = null;
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $hecho = self::etiqueta($c).': '.match ($estado) {
            'correcto' => self::mostrar($c).' (correcto)', 'no_legible' => 'no se lee', default => self::mostrar($c).' (corregido)'
        };
        $mensajeId ? $this->tg->editar($ctx->chatId, $mensajeId, $hecho) : $this->tg->enviar($ctx->chatId, 'Anotado. '.$hecho);
        $ctx->bitacora('entrante', 'callback', 'campo_revisado', 'borrador_documento', $b->id, ['ruta' => $ruta, 'estado' => $estado]);
        $this->preguntar($ctx, $p->id);

        return true;
    }

    private function pedirValor(Contexto $ctx, object $p, string $ruta, ?int $mensajeId): bool
    {
        $c = self::campo(json_decode($this->borrador($p)->campos, true), $ruta);
        $opciones = self::opciones($c, json_decode($this->borrador($p)->campos, true));
        if ($opciones !== null) {
            $filas = array_chunk(array_map(fn ($i, $o) => [$o['etiqueta'], "b:{$p->id}:v:{$i}"], array_keys($opciones), $opciones), 2);
            $this->tg->enviar($ctx->chatId, 'Elegí el valor correcto para '.self::etiqueta($c).':', TelegramApi::botones($filas));

            return true;
        }
        $datos = json_decode($p->datos, true);
        $datos['esperando'] = $ruta;
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $this->tg->enviar($ctx->chatId, 'Escribime el valor correcto de '.self::etiqueta($c).'. '.self::formato($c['tipo']));

        return true;
    }

    private function opcion(Contexto $ctx, object $p, string $ruta, int $i, ?int $mensajeId): bool
    {
        $campos = json_decode($this->borrador($p)->campos, true);
        $opciones = self::opciones(self::campo($campos, $ruta), $campos) ?? [];
        if (! isset($opciones[$i])) {
            return false;
        }

        return $this->decidir($ctx, $p, $ruta, 'editado', $opciones[$i]['valor'], $mensajeId);
    }

    private function final(Contexto $ctx, string $propuestaId): void
    {
        $p = DB::table('telegram_propuestas')->where('id', $propuestaId)->first();
        $b = $this->borrador($p);
        $adv = json_decode($b->advertencias, true);
        $lista = $adv === [] ? '' : "\n\nAdvertencias:\n".implode("\n", array_map(fn ($a) => '- '.$a['mensaje'], $adv));
        $boton = $adv === [] ? ['Registrar', "b:{$p->id}:si"] : ['Registrar igual', "b:{$p->id}:forzar"];
        $this->tg->enviar($ctx->chatId, "Así queda:\n\n".self::resumen(json_decode($b->campos, true)).$lista
            ."\n\n¿Lo registro en {$ctx->est->nombre}? Se cargan los eventos que surgen del documento y el documento queda vinculado.",
            TelegramApi::botones([[$boton, ['Cancelar', "b:{$p->id}:no"]]]));
    }

    private function confirmar(Contexto $ctx, object $p, bool $forzar, ?int $mensajeId): bool
    {
        $b = $this->borrador($p);
        $pendientes = array_values(array_filter(json_decode($b->campos, true), fn ($c) => $c['estado'] === 'pendiente'));
        try {
            if ($pendientes !== []) {
                // El usuario vio estos valores en el resumen y confirma: se registran como revisados por él.
                $b = $this->revisar->handle($ctx->est, $ctx->usuario, $b, array_map(fn ($c) => ['ruta' => $c['ruta'], 'estado' => $c['valor'] === null ? 'no_legible' : 'correcto'], $pendientes), null, null);
            }
            $b = $this->confirmar->handle($ctx->est, $ctx->usuario, $b, $forzar, 'telegram');
        } catch (ApiException $e) {
            if ($e->codigo === 'requiere_confirmacion') {
                $this->tg->enviar($ctx->chatId, "Hay advertencias:\n".implode("\n", array_map(fn ($a) => '- '.$a['mensaje'], $e->advertencias))."\n\n¿Lo registro igual?",
                    TelegramApi::botones([[['Registrar igual', "b:{$p->id}:forzar"], ['Cancelar', "b:{$p->id}:no"]]]));

                return true;
            }

            return $this->noSePudo($ctx, $p, $b, $e->getMessage(), $e->codigo);
        } catch (EventoRechazado $e) {
            return $this->noSePudo($ctx, $p, $b, $e->getMessage(), $e->codigo);
        }
        $eventos = json_decode((string) $b->evento_ids, true) ?? [];
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'confirmada', 'updated_at' => now()]);
        $mensajeId && $this->tg->editar($ctx->chatId, $mensajeId, 'Confirmado.');
        $this->tg->enviar($ctx->chatId, count($eventos) === 0
            ? 'Listo: el documento quedó revisado y guardado (no surgieron eventos para registrar).'
            : 'Listo: registré '.(count($eventos) === 1 ? '1 evento' : count($eventos).' eventos').' y el documento quedó vinculado. Lo ves en la app y en el panel.');
        $ctx->bitacora('entrante', 'callback', 'borrador_confirmado', 'borrador_documento', $b->id, ['eventos' => count($eventos), 'advertencias' => $forzar]);

        return true;
    }

    private function noSePudo(Contexto $ctx, object $p, object $b, string $mensaje, string $codigo): bool
    {
        $this->tg->enviar($ctx->chatId, "No se pudo registrar: {$mensaje}\nEl documento y tu revisión quedaron guardados: podés terminarlo desde la app o el panel.");
        $ctx->bitacora('entrante', 'callback', 'borrador_rechazado', 'borrador_documento', $b->id, ['codigo' => $codigo]);

        return true;
    }

    private function descartar(Contexto $ctx, object $p, ?int $mensajeId): bool
    {
        $b = $this->borrador($p);
        if (! in_array($b->estado, ['confirmado', 'descartado'], true)) {
            DB::table('borradores_documento')->where('id', $b->id)->update(['estado' => 'descartado', 'descartado_por' => $ctx->usuario->id, 'descartado_en' => now(),
                'row_version' => $b->row_version + 1, 'updated_at' => now()]);
            $this->auditoria->handle($ctx->est->id, $ctx->usuario->id, 'borrador_documento', $b->id, 'descarte', ['estado' => $b->estado], ['estado' => 'descartado', 'canal' => 'telegram']);
        }
        DB::table('telegram_propuestas')->where('id', $p->id)->update(['estado' => 'cancelada', 'updated_at' => now()]);
        $mensajeId && $this->tg->editar($ctx->chatId, $mensajeId, 'Cancelado.');
        $this->tg->enviar($ctx->chatId, 'Listo, no registré nada. El documento queda guardado en Documentos.');
        $ctx->bitacora('entrante', 'callback', 'borrador_descartado', 'borrador_documento', $b->id);

        return true;
    }

    private function borrador(object $p): object
    {
        return DB::table('borradores_documento')->where('id', $p->borrador_id)->first();
    }

    /**
     * Rutas a preguntar, en el orden de los campos.
     *
     * @param  list<array<string, mixed>>  $campos
     * @param  list<array<string, mixed>>  $advertencias
     * @return list<string>
     */
    public static function dudosos(array $campos, array $advertencias): array
    {
        $conAdvertencia = array_filter(array_column($advertencias, 'ruta'));
        $out = [];
        foreach ($campos as $c) {
            if ($c['estado'] !== 'pendiente') {
                continue;
            }
            $leido = $c['valor'] !== null;
            if (in_array($c['ruta'], $conAdvertencia, true)
                || ($leido && ($c['confianza'] === null || $c['confianza'] < self::UMBRAL || $c['sugerido'] || $c['alternativas'] !== []))
                || (! $leido && ($c['critico'] || $c['valor_extraido'] !== null))) {
                $out[] = $c['ruta'];
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $campos */
    public static function resumen(array $campos): string
    {
        $lineas = [];
        $vacios = 0;
        foreach ($campos as $c) {
            if ($c['valor'] === null || in_array($c['estado'], ['no_legible', 'no_aplica'], true)) {
                $vacios++;

                continue;
            }
            $lineas[] = '- '.self::etiqueta($c).': '.self::mostrar($c);
        }

        return implode("\n", $lineas).($vacios > 0 ? "\n(".($vacios === 1 ? '1 casillero vacío o ilegible' : "{$vacios} casilleros vacíos o ilegibles").')' : '');
    }

    /** @param list<array<string, mixed>> $campos */
    private static function campo(array $campos, string $ruta): ?array
    {
        foreach ($campos as $c) {
            if ($c['ruta'] === $ruta) {
                return $c;
            }
        }

        return null;
    }

    private static function etiqueta(array $c): string
    {
        return preg_match('/^([a-z_]+)\.(\d+)\./', $c['ruta'], $m) ? ucfirst($m[1]).' '.((int) $m[2] + 1).' · '.$c['etiqueta'] : $c['etiqueta'];
    }

    private static function mostrar(array $c): string
    {
        $v = $c['valor'];

        return match (true) {
            $v === null => '(vacío)',
            $c['tipo'] === 'fecha' && is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 => "{$m[3]}/{$m[2]}/{$m[1]}",
            $c['tipo'] === 'booleano' => $v ? 'sí' : 'no',
            in_array($c['tipo'], ['opcion', 'categoria'], true) => (string) (collect($c['opciones'] ?? [])->firstWhere('valor', $v)['etiqueta'] ?? $v),
            default => (string) $v,
        };
    }

    /**
     * Opciones para botones (las categorías, filtradas por la especie de la fila si se conoce).
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<array{valor: string, etiqueta: string}>|null
     */
    private static function opciones(?array $c, array $campos): ?array
    {
        if ($c === null || $c['tipo'] === 'booleano') {
            return $c === null ? null : [['valor' => true, 'etiqueta' => 'Sí'], ['valor' => false, 'etiqueta' => 'No']];
        }
        if (! in_array($c['tipo'], ['opcion', 'categoria'], true) || empty($c['opciones'])) {
            return null;
        }
        $ops = $c['opciones'];
        if ($c['tipo'] === 'categoria' && preg_match('/^(\w+)\.(\d+)\./', $c['ruta'], $m)) {
            $esp = self::campo($campos, "{$m[1]}.{$m[2]}.especie")['valor'] ?? null;
            if (is_string($esp)) {
                $ops = array_values(array_filter($ops, fn ($o) => str_starts_with($o['valor'], $esp.'_')));
            }
        }

        return array_slice($ops, 0, 24);
    }

    private static function formato(string $tipo): string
    {
        return match ($tipo) {
            'fecha' => 'Escribila como día/mes/año (por ejemplo 12/05/2026).',
            'entero' => 'Escribí solo el número.',
            default => 'Escribilo como figura en el papel.',
        };
    }

    /** Texto → valor del tipo del campo; false si no se entiende. */
    private static function interpretar(string $tipo, string $texto): mixed
    {
        $t = trim($texto);

        return match ($tipo) {
            'fecha' => match (true) {
                (bool) preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2}|\d{4})$/', $t, $m) => checkdate((int) $m[2], (int) $m[1], $a = ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]))
                    ? sprintf('%04d-%02d-%02d', $a, (int) $m[2], (int) $m[1]) : false,
                (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) => $t,
                default => false,
            },
            'entero' => preg_match('/^[\d.\s]+$/', $t) && preg_replace('/\D/', '', $t) !== '' ? (int) preg_replace('/\D/', '', $t) : false,
            default => $t === '' ? false : mb_substr($t, 0, 200),
        };
    }
}
