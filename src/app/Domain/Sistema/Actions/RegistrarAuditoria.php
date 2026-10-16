<?php

namespace App\Domain\Sistema\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Bitácora de lo mutable (modelo-datos.md §12): maestros, visibilidad, membresías, perfil.
 */
final class RegistrarAuditoria
{
    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function handle(?string $establecimientoId, ?string $usuarioId, string $entidad, ?string $entidadId, string $accion, ?array $antes = null, ?array $despues = null, ?string $motivo = null): void
    {
        $ip = request()?->ip();

        DB::table('auditoria')->insert([
            'establecimiento_id' => $establecimientoId,
            'usuario_id' => $usuarioId,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'accion' => $accion,
            'antes' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
            'despues' => $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
            'motivo' => $motivo,
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
            'created_at' => now(),
        ]);
    }
}
