<?php

namespace App\Domain\Telegram;

use Illuminate\Support\Facades\DB;

/**
 * Bitácora del bot (TPA-77): qué entró (tipo, comando), qué hizo el bot (acción, entidad) y qué salió. **Sin el texto
 * libre del usuario ni las respuestas**: solo metadatos (largo, herramientas usadas, códigos de error). Append-only.
 */
final class Bitacora
{
    /** @param array<string, mixed> $detalle */
    public static function registrar(
        int $chatId,
        string $direccion,
        string $tipo,
        ?object $vinculo = null,
        ?string $accion = null,
        ?string $entidadTipo = null,
        ?string $entidadId = null,
        array $detalle = [],
    ): void {
        DB::table('telegram_mensajes')->insert([
            'vinculo_id' => $vinculo?->id, 'usuario_id' => $vinculo?->usuario_id, 'establecimiento_id' => $vinculo?->establecimiento_id,
            'chat_id' => $chatId, 'direccion' => $direccion, 'tipo' => $tipo, 'accion' => $accion, 'entidad_tipo' => $entidadTipo, 'entidad_id' => $entidadId,
            'detalle' => json_encode((object) $detalle, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
