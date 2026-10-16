<?php

namespace App\Domain\Documentos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publicar un documento en el perfil o volverlo a privado (TPA-36). La visibilidad **no** es evidencia (no va en el sobre):
 * es configuración mutable, auditada. Fiscal o tipo no publicable → 422 `no_publicable` (además del CHECK y el trigger).
 */
final class GestionarVisibilidad
{
    public function __construct(private readonly RegistrarAuditoria $auditoria, private readonly RegistrarCambio $registrarCambio) {}

    public function handle(Establecimiento $est, object $doc, User $usuario, string $visibilidad, int $rowVersion): void
    {
        DB::transaction(function () use ($est, $doc, $usuario, $visibilidad, $rowVersion) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $d = DB::table('documentos')->where('id', $doc->id)->lockForUpdate()->first();
            if ((int) $d->row_version !== $rowVersion) {
                throw ApiException::conflictoRowVersion(['row_version' => (int) $d->row_version]);
            }
            if ($d->visibilidad === $visibilidad) {
                return;
            }
            if ($visibilidad === 'perfil') {
                $publicable = (bool) DB::table('tipos_documento')->where('codigo', $d->tipo_codigo)->value('publicable');
                if ($d->privacidad === 'fiscal' || ! $publicable) {
                    throw new ApiException(422, 'no_publicable', __('Este documento no se puede publicar (fiscal o tipo no publicable). Podés compartirlo por enlace.'));
                }
                if ($d->estado === 'revocado') {
                    throw ApiException::reglaBloqueante(__('Un documento revocado no se publica.'));
                }
            }
            DB::table('documentos')->where('id', $d->id)->update([
                'visibilidad' => $visibilidad, 'publicado_en' => $visibilidad === 'perfil' ? now() : null, 'publicado_por' => $visibilidad === 'perfil' ? $usuario->id : null,
                'row_version' => $d->row_version + 1, 'updated_at' => now(),
            ]);
            $this->registrarCambio->handle($est->id, 'documento', $d->id, visibilidad: RegistrarDocumentoVersion::visibilidadSync($d->privacidad), cadena: $cadena);
            $this->auditoria->handle($est->id, $usuario->id, 'documento', $d->id, 'visibilidad', ['visibilidad' => $d->visibilidad], ['visibilidad' => $visibilidad]);
        });
    }
}
