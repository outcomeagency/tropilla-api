<?php

namespace App\Domain\Documentos\Borradores\Actions;

use App\Domain\Documentos\Borradores\Borradores;
use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pide la lectura de un documento ya capturado (TPA-61). La app lo encola offline con un UUIDv7 propio y lo manda
 * cuando el documento ya subió por el push: **idempotente por id** (el reintento devuelve el mismo borrador). Las páginas
 * son los archivos de la versión vigente del documento (originales, SHA-256 verificado por el servidor al subir).
 * Si alguna página todavía no subió queda `esperando_archivos`; al completarse la subida pasa a `en_cola` sola.
 */
final class CrearBorrador
{
    public const MAX_PAGINAS = 10;

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  array{id: string, documento_id: string, familia: string}  $d
     * @return array{0: object, 1: bool} [borrador, creado]
     */
    public function handle(Establecimiento $est, User $usuario, array $d, ?string $dispositivoId): array
    {
        $id = strtolower($d['id']);
        if ($existente = DB::table('borradores_documento')->where('id', $id)->first()) {
            if ($existente->establecimiento_id !== $est->id || $existente->documento_id !== strtolower($d['documento_id']) || $existente->familia !== $d['familia']) {
                throw new ApiException(409, 'id_reutilizado', __('Ese id ya se usó para otro borrador.'));
            }

            return [$existente, false];
        }
        Familias::get($d['familia']);

        $doc = DB::table('documentos')->where('id', strtolower($d['documento_id']))->first();
        if ($doc === null) {
            throw new ApiException(409, 'dependencia_faltante', __('El documento todavía no llegó al servidor: se reintenta después de sincronizar.'));
        }
        if ($doc->establecimiento_id !== $est->id) {
            throw ApiException::noEncontrado();
        }
        if ($doc->privacidad === 'fiscal' && ! Gate::allows('documentos.fiscal', $est)) {
            throw ApiException::sinPermiso();
        }
        if ($doc->estado !== 'vigente') {
            throw ApiException::reglaBloqueante(__('El documento está revocado.'));
        }
        $version = DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $doc->version_vigente)->where('en_revision', false)->first();
        $paginas = DB::table('documento_version_archivos')->where('documento_version_id', $version->id)->orderBy('orden')->pluck('archivo_sha256')->all();
        if (count($paginas) > self::MAX_PAGINAS) {
            throw ApiException::validacion(__('Se leen hasta :n páginas por documento.', ['n' => self::MAX_PAGINAS]), ['documento_id' => [__('Demasiadas páginas.')]]);
        }

        $borrador = DB::transaction(function () use ($est, $usuario, $id, $doc, $version, $d, $dispositivoId) {
            DB::table('borradores_documento')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'documento_id' => $doc->id, 'documento_version_id' => $version->id, 'familia' => $d['familia'],
                'estado' => 'esperando_archivos', 'creado_por' => $usuario->id, 'dispositivo_id' => $dispositivoId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $usuario->id, 'borrador_documento', $id, 'alta', null, ['documento_id' => $doc->id, 'familia' => $d['familia']]);

            return DB::table('borradores_documento')->where('id', $id)->first();
        });
        Borradores::encolarSiListo($borrador->id);

        return [DB::table('borradores_documento')->where('id', $id)->first(), true];
    }
}
