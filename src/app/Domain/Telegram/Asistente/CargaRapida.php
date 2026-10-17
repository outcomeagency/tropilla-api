<?php

namespace App\Domain\Telegram\Asistente;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Domain\Telegram\Contexto;
use App\Domain\Telegram\TelegramApi;
use App\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cargas rápidas por texto o audio (TPA-77): nacimiento, pesaje, muerte y tratamiento. El asistente **propone**
 * (`proponer_carga`), el servidor resuelve caravanas y arma el input del evento, y el bot pide confirmación con botones.
 * Recién con «Registrar» entra por el camino de siempre (`ArmarSobreWeb` + `RegistrarEvento`, origen `telegram`): mismos
 * permisos, esquema, reglas, advertencias (se vuelven a preguntar), hash y cadena. Nunca se registra sin confirmar.
 */
final class CargaRapida
{
    public const TIPOS = ['nacimiento', 'pesaje', 'muerte', 'tratamiento'];

    public function __construct(private readonly TelegramApi $tg, private readonly RegistrarEvento $registrar) {}

    /** @return array<string, mixed> functionDeclaration de `proponer_carga` */
    public static function declaracion(Contexto $ctx): ?array
    {
        if (! ($ctx->puede('hacienda.crear') || $ctx->puede('reproduccion.crear') || $ctx->puede('sanidad.crear'))) {
            return null;
        }
        $causas = DB::table('catalogo_items')->where('lista', 'causas_muerte')
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $ctx->est->id))->orderBy('codigo')->pluck('codigo')->all();

        return [
            'name' => 'proponer_carga',
            'description' => 'Prepara (NO registra) la carga de un evento simple para que el usuario la confirme con un botón: nacimiento, pesaje, muerte o '
                .'tratamiento. Usala cuando el usuario cuenta algo que pasó y quiere anotarlo. Si falta un dato obligatorio, preguntáselo antes: no lo inventes.',
            'parameters' => ['type' => 'OBJECT', 'required' => ['tipo'], 'properties' => [
                'tipo' => ['type' => 'STRING', 'enum' => self::TIPOS],
                'caravanas' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Caravana(s) del o los animales. Pesaje y muerte: una. Tratamiento: una o más. Nacimiento: la de la madre, si la dijo'],
                'fecha' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; omitir si fue hoy'],
                'peso_kg' => ['type' => 'NUMBER', 'description' => 'Pesaje: peso del animal (obligatorio). Nacimiento: peso de la cría (opcional)'],
                'metodo_peso' => ['type' => 'STRING', 'enum' => ['balanza', 'cinta', 'estimado']],
                'causa' => ['type' => 'STRING', 'enum' => $causas ?: ['desconocida', 'otra'], 'description' => 'Muerte: causa (obligatoria; si no la sabe, desconocida)'],
                'causa_detalle' => ['type' => 'STRING', 'description' => 'Muerte: detalle o hallazgos, si los dijo'],
                'sexo_cria' => ['type' => 'STRING', 'enum' => ['M', 'H'], 'description' => 'Nacimiento: M macho, H hembra (obligatorio)'],
                'cria_viva' => ['type' => 'BOOLEAN', 'description' => 'Nacimiento: false si nació muerta'],
                'caravana_cria' => ['type' => 'STRING', 'description' => 'Nacimiento: caravana que se le puso a la cría, si la dijo'],
                'especie' => ['type' => 'STRING', 'enum' => ['BOV', 'BUB'], 'description' => 'Nacimiento sin madre identificada: BOV bovino, BUB búfalo'],
                'diagnostico' => ['type' => 'STRING', 'description' => 'Tratamiento: diagnóstico presuntivo o motivo (obligatorio)'],
                'producto' => ['type' => 'STRING', 'description' => 'Tratamiento: producto aplicado, como lo dijo'],
                'dosis' => ['type' => 'STRING', 'description' => 'Tratamiento: dosis aplicada, como la dijo el usuario (no sugerir dosis)'],
                'retiro_carne_dias' => ['type' => 'INTEGER', 'description' => 'Tratamiento: días de retiro para carne, si los dijo'],
                'veterinario' => ['type' => 'STRING'],
            ]],
        ];
    }

    /**
     * Valida y guarda la propuesta; manda el mensaje con botones. `error` vuelve al modelo para que repregunte.
     *
     * @param  array<string, mixed>  $a
     * @return array{ok?: true, error?: string}
     */
    public function proponer(Contexto $ctx, array $a): array
    {
        $tipo = (string) ($a['tipo'] ?? '');
        if (! in_array($tipo, self::TIPOS, true)) {
            return ['error' => 'Por ahora el bot carga nacimientos, pesajes, muertes y tratamientos.'];
        }
        $fecha = $this->fecha($ctx, $a['fecha'] ?? null);
        if ($fecha === null) {
            return ['error' => 'La fecha no es válida o es futura: pedile al usuario la fecha (día y mes).'];
        }
        $animales = [];
        foreach (array_slice(array_values(array_filter((array) ($a['caravanas'] ?? []), 'is_string')), 0, 50) as $c) {
            $r = $this->animal($ctx, $c);
            if (is_string($r)) {
                return ['error' => $r];
            }
            $animales[$r->animal_id] = $r;
        }

        $r = match ($tipo) {
            'pesaje' => $this->pesaje($ctx, $a, $animales),
            'muerte' => $this->muerte($ctx, $a, $animales),
            'tratamiento' => $this->tratamiento($ctx, $a, $animales),
            'nacimiento' => $this->nacimiento($ctx, $a, $animales, $fecha),
        };
        if (isset($r['error'])) {
            return $r;
        }
        [$evento, $resumen, $ability] = $r;
        if (! $ctx->puede($ability)) {
            return ['error' => 'El rol del usuario en este establecimiento no permite registrar ese evento. Decíselo.'];
        }
        $evento['id'] = (string) Str::uuid7();
        $evento['ocurrido_en'] = $fecha;
        $resumen .= ' · '.Carbon::parse($fecha)->format('d/m/Y');

        $id = (string) Str::uuid7();
        DB::table('telegram_propuestas')->where('vinculo_id', $ctx->vinculo->id)->where('tipo', 'evento')->where('estado', 'pendiente')
            ->update(['estado' => 'cancelada', 'updated_at' => now()]);
        DB::table('telegram_propuestas')->insert([
            'id' => $id, 'vinculo_id' => $ctx->vinculo->id, 'usuario_id' => $ctx->usuario->id, 'establecimiento_id' => $ctx->est->id, 'tipo' => 'evento',
            'estado' => 'pendiente', 'datos' => json_encode(['in' => $evento, 'resumen' => $resumen, 'tipo_carga' => $tipo], JSON_UNESCAPED_UNICODE),
            'expira_en' => now()->addHours((int) config('tropilla.telegram.propuesta_horas')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $msg = $this->tg->enviar($ctx->chatId, "Voy a registrar en {$ctx->est->nombre}:\n{$resumen}\n\n¿Confirmás?", self::botones($id));
        DB::table('telegram_propuestas')->where('id', $id)->update(['mensaje_id' => $msg]);
        $ctx->bitacora('saliente', 'propuesta', 'propuesta_evento', 'telegram_propuesta', $id, ['tipo_carga' => $tipo]);

        return ['ok' => true];
    }

    /** Botón de la propuesta: `si` | `forzar` (registrar con advertencias) | `no`. */
    public function responder(Contexto $ctx, string $propuestaId, string $accion, ?int $mensajeId): void
    {
        $lock = Cache::lock('tg-propuesta-'.$propuestaId, 30);
        if (! $lock->get()) {
            return; // doble toque: el primero está registrando
        }
        try {
            $p = DB::table('telegram_propuestas')->where('id', $propuestaId)->where('vinculo_id', $ctx->vinculo->id)->where('tipo', 'evento')->first();
            if ($p === null || $p->estado !== 'pendiente') {
                $this->tg->enviar($ctx->chatId, 'Esa propuesta ya no está pendiente.');

                return;
            }
            $datos = json_decode($p->datos, true);
            $mensajeId ??= $p->mensaje_id !== null ? (int) $p->mensaje_id : null;
            $editar = fn (string $t, ?array $kb = null) => $mensajeId ? $this->tg->editar($ctx->chatId, $mensajeId, $t, $kb) : $this->tg->enviar($ctx->chatId, $t, $kb);
            if (now()->gte($p->expira_en)) {
                $this->estado($p->id, 'expirada');
                $editar("Venció la propuesta: no se registró nada.\n{$datos['resumen']}");

                return;
            }
            if ($accion === 'no') {
                $this->estado($p->id, 'cancelada');
                $editar("Cancelado: no se registró nada.\n{$datos['resumen']}");
                $ctx->bitacora('entrante', 'callback', 'propuesta_cancelada', 'telegram_propuesta', $p->id);

                return;
            }
            $est = Establecimiento::query()->find($p->establecimiento_id);
            $in = json_decode(json_encode($datos['in'], JSON_UNESCAPED_UNICODE), false);
            if ($existente = Evento::query()->where('id', $in->id)->where('establecimiento_id', $p->establecimiento_id)->first()) {
                $this->confirmada($p->id, $existente->id);
                $editar("Ya estaba registrado:\n{$datos['resumen']}");

                return;
            }
            try {
                $r = $this->registrar->handle($est, $ctx->usuario, ArmarSobreWeb::alta($est, $ctx->usuario, $in, 'telegram'),
                    ['canal' => 'web', 'confirmar_advertencias' => $accion === 'forzar']);
            } catch (ApiException $e) {
                if ($e->codigo === 'requiere_confirmacion') {
                    $lista = implode("\n", array_map(fn ($w) => '- '.$w['mensaje'], $e->advertencias));
                    $editar("{$datos['resumen']}\n\nOjo:\n{$lista}\n\n¿Lo registro igual?", TelegramApi::botones([[['Registrar igual', "e:{$p->id}:forzar"], ['Cancelar', "e:{$p->id}:no"]]]));

                    return;
                }
                $this->fallida($ctx, $p, $datos, $e->getMessage(), $e->codigo, $editar);

                return;
            } catch (EventoRechazado $e) {
                $this->fallida($ctx, $p, $datos, $e->getMessage(), $e->codigo, $editar);

                return;
            }
            $this->confirmada($p->id, $r->evento->id);
            $editar("Listo, quedó registrado:\n{$datos['resumen']}");
            $ctx->bitacora('entrante', 'callback', 'evento_registrado', 'evento', $r->evento->id, ['tipo' => $r->evento->tipo, 'advertencias' => $accion === 'forzar']);
        } finally {
            $lock->release();
        }
    }

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    public static function botones(string $id): array
    {
        return TelegramApi::botones([[['Registrar', "e:{$id}:si"], ['Cancelar', "e:{$id}:no"]]]);
    }

    /** @param array<string, object> $animales */
    private function pesaje(Contexto $ctx, array $a, array $animales): array
    {
        if (count($animales) !== 1) {
            return ['error' => 'Para un pesaje necesito la caravana de un animal.'];
        }
        $peso = self::decimal($a['peso_kg'] ?? null);
        if ($peso === null || (float) $peso > 2500) {
            return ['error' => 'Falta el peso en kg (o no es válido).'];
        }
        $metodo = in_array($a['metodo_peso'] ?? null, ['balanza', 'cinta', 'estimado'], true) ? $a['metodo_peso'] : 'balanza';
        $an = reset($animales);

        return [[
            'tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$an->animal_id]],
            'payload' => ['modo' => 'individual', 'peso_kg' => $peso, 'metodo' => $metodo],
        ], 'Pesaje · '.self::etiqueta($an)." · {$peso} kg ({$metodo})", 'hacienda.crear'];
    }

    /** @param array<string, object> $animales */
    private function muerte(Contexto $ctx, array $a, array $animales): array
    {
        if (count($animales) !== 1) {
            return ['error' => 'Para registrar una muerte necesito la caravana de un animal (de a uno).'];
        }
        $causa = is_string($a['causa'] ?? null) ? $a['causa'] : 'desconocida';
        $item = DB::table('catalogo_items')->where('lista', 'causas_muerte')->where('codigo', $causa)
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $ctx->est->id))->first();
        if ($item === null) {
            return ['error' => 'Causa de muerte desconocida: usá una del listado.'];
        }
        $detalle = self::texto($a['causa_detalle'] ?? null, 200);
        $payload = ['causa' => $causa];
        if ($detalle !== null) {
            $payload[$causa === 'otra' ? 'causa_otra' : 'hallazgos'] = $detalle;
        }
        $an = reset($animales);
        $nombre = Catalogos::nombre($item->nombres ?? null, 'es') ?? $causa;

        return [[
            'tipo' => 'hacienda.muerte', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$an->animal_id]], 'payload' => $payload,
        ], 'Muerte · '.self::etiqueta($an)." · causa: {$nombre}".($detalle ? " ({$detalle})" : ''), 'hacienda.crear'];
    }

    /** @param array<string, object> $animales */
    private function tratamiento(Contexto $ctx, array $a, array $animales): array
    {
        if ($animales === []) {
            return ['error' => 'Para un tratamiento necesito la caravana de al menos un animal.'];
        }
        $diag = self::texto($a['diagnostico'] ?? null, 200);
        if ($diag === null) {
            return ['error' => 'Falta el diagnóstico presuntivo o el motivo del tratamiento: preguntalo.'];
        }
        $payload = array_filter([
            'diagnostico_presuntivo' => $diag, 'producto_nombre' => self::texto($a['producto'] ?? null, 200), 'dosis_aplicada' => self::texto($a['dosis'] ?? null, 200),
            'veterinario' => self::texto($a['veterinario'] ?? null, 200),
            'retiro_carne_dias' => isset($a['retiro_carne_dias']) && is_numeric($a['retiro_carne_dias']) && $a['retiro_carne_dias'] >= 0 ? (int) $a['retiro_carne_dias'] : null,
        ], fn ($v) => $v !== null);
        $ids = array_keys($animales);
        sort($ids);
        $quienes = count($animales) === 1 ? self::etiqueta(reset($animales)) : count($animales).' animales ('.implode(', ', array_map(fn ($x) => $x->identificacion_principal ?? '?', array_slice($animales, 0, 5))).(count($animales) > 5 ? '…' : '').')';
        $texto = "Tratamiento · {$quienes} · {$diag}".(isset($payload['producto_nombre']) ? " · {$payload['producto_nombre']}" : '')
            .(isset($payload['dosis_aplicada']) ? " · dosis: {$payload['dosis_aplicada']}" : '').(isset($payload['retiro_carne_dias']) ? " · retiro carne {$payload['retiro_carne_dias']} d" : '');

        return [['tipo' => 'sanidad.tratamiento', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids], 'payload' => $payload], $texto, 'sanidad.crear'];
    }

    /** @param array<string, object> $animales */
    private function nacimiento(Contexto $ctx, array $a, array $animales, string $fecha): array
    {
        if (count($animales) > 1) {
            return ['error' => 'Un nacimiento es de una madre: decime una sola caravana.'];
        }
        $sexo = in_array($a['sexo_cria'] ?? null, ['M', 'H'], true) ? $a['sexo_cria'] : null;
        if ($sexo === null) {
            return ['error' => 'Falta el sexo de la cría (macho o hembra): preguntalo.'];
        }
        $viva = ($a['cria_viva'] ?? true) !== false;
        $madre = $animales === [] ? null : reset($animales);
        if ($madre !== null && ($madre->sexo !== 'H' || $madre->estado !== 'activo')) {
            return ['error' => 'La caravana de la madre no es de una hembra activa del establecimiento.'];
        }
        $especie = $madre?->especie_codigo ?? (in_array($a['especie'] ?? null, $ctx->est->especies, true) ? $a['especie'] : (count($ctx->est->especies) === 1 ? $ctx->est->especies[0] : null));
        if ($especie === null) {
            return ['error' => 'Sin la madre no sé la especie de la cría: preguntá si es bovino o búfalo.'];
        }
        if ($madre === null && ! $viva) {
            return ['error' => 'Para anotar una cría muerta necesito la caravana de la madre.'];
        }
        $peso = isset($a['peso_kg']) ? self::decimal($a['peso_kg']) : null;
        $cria = ['viva' => $viva];
        $texto = $sexo === 'H' ? 'hembra' : 'macho';
        if ($viva) {
            $cat = DB::table('categorias')->where('especie_codigo', $especie)->where('sexo', $sexo)->where('es_cria', true)->where('activa', true)->orderBy('orden')->first();
            if ($cat === null) {
                return ['error' => 'No hay una categoría de cría para esa especie y sexo.'];
            }
            $animal = array_filter([
                'animal_id' => (string) Str::uuid7(), 'especie' => $especie, 'sexo' => $sexo, 'categoria' => $cat->codigo, 'fecha_nacimiento' => $fecha,
                'madre_id' => $madre?->animal_id,
                'identificaciones' => ($car = self::texto($a['caravana_cria'] ?? null, 64)) ? [['tipo' => 'caravana_visual', 'valor' => $car]] : null,
            ], fn ($v) => $v !== null);
            $cria['animal'] = $animal;
            $texto .= ' viva'.(isset($animal['identificaciones']) ? ', caravana '.$animal['identificaciones'][0]['valor'] : '');
        } else {
            $cria['sexo'] = $sexo;
            $texto .= ' nacida muerta';
        }
        if ($peso !== null) {
            $cria['peso_kg'] = $peso;
            $texto .= ", {$peso} kg";
        }
        if ($madre !== null) {
            return [[
                'tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$madre->animal_id]], 'payload' => ['tipo' => 'normal', 'crias' => [$cria]],
            ], 'Parto · madre '.self::etiqueta($madre)." · cría {$texto}", 'reproduccion.crear'];
        }

        return [[
            'tipo' => 'hacienda.nacimiento', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$cria['animal']['animal_id']]], 'payload' => ['crias' => [$cria]],
        ], 'Nacimiento (sin madre identificada) · '.($especie === 'BUB' ? 'búfalo' : 'bovino')." · cría {$texto}", 'hacienda.crear'];
    }

    /** Animal activo o de baja del establecimiento por identificación vigente. @return object|string mensaje de error */
    private function animal(Contexto $ctx, string $caravana): object|string
    {
        $valor = Identificaciones::normalizar(mb_substr($caravana, 0, 64));
        $ids = $valor === '' ? [] : DB::table('animal_identificaciones')->where('establecimiento_id', $ctx->est->id)
            ->whereRaw(Identificaciones::SQL_SIN_CEROS.' = ?', [Identificaciones::sinCeros($valor)])->where('vigente', true)->distinct()->limit(3)->pluck('animal_id')->all();
        $filas = ConsultasAnimales::base($ctx->est->id)->whereIn('a.animal_id', $ids)->where('a.estado', '<>', 'anulado')->get();
        if ($filas->isEmpty()) {
            return "No encontré la caravana {$caravana} en {$ctx->est->nombre}. Pedile que la revise.";
        }
        if ($filas->count() > 1) {
            return "Hay más de un animal con la identificación {$caravana}: pedile otra identificación (RFID o tatuaje).";
        }

        return $filas->first();
    }

    private function fecha(Contexto $ctx, mixed $f): ?string
    {
        $hoy = $ctx->hoy();
        if ($f === null || $f === '') {
            return $hoy;
        }
        if (! is_string($f) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || ! checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4))) {
            return null;
        }

        return $f > $hoy || $f < Carbon::parse($hoy)->subYears(2)->toDateString() ? null : $f;
    }

    private function confirmada(string $id, string $eventoId): void
    {
        DB::table('telegram_propuestas')->where('id', $id)->update(['estado' => 'confirmada', 'evento_id' => $eventoId, 'updated_at' => now()]);
    }

    private function estado(string $id, string $estado): void
    {
        DB::table('telegram_propuestas')->where('id', $id)->update(['estado' => $estado, 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $datos */
    private function fallida(Contexto $ctx, object $p, array $datos, string $mensaje, string $codigo, \Closure $editar): void
    {
        $this->estado($p->id, 'fallida');
        $editar("No se pudo registrar: {$mensaje}\n{$datos['resumen']}\n\nNo se guardó nada; podés cargarlo desde la app o el panel.");
        $ctx->bitacora('entrante', 'callback', 'evento_rechazado', 'telegram_propuesta', $p->id, ['codigo' => $codigo]);
    }

    private static function etiqueta(object $a): string
    {
        $cat = Catalogos::nombre($a->categoria_nombres ?? null, 'es') ?? $a->categoria_codigo;

        return 'caravana '.($a->identificacion_principal ?? '?')." ({$cat}".($a->lote_nombre ? ", lote {$a->lote_nombre}" : '').')';
    }

    /** Decimal canónico positivo como string («350», «32.5»). */
    public static function decimal(mixed $v): ?string
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        if (! is_numeric($v) || (float) $v <= 0) {
            return null;
        }
        $s = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return $s === '' || $s === '0' ? null : $s;
    }

    private static function texto(mixed $v, int $max): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
    }
}
