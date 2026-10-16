<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Resolver una revisión (sync-e-integridad §7.5): `reaplicada` = corrección nueva sobre la vigente (con el
 * motivo referenciando la revisión), `descartada` / `resuelta` = cierre con motivo. La versión en revisión
 * nunca cambia (M4): queda en el historial marcada «no aplicada».
 */
final class ResolverRevision
{
    public function __construct(private readonly CorregirEvento $corregir, private readonly RegistrarCambio $registrarCambio) {}

    public function handle(Establecimiento $est, User $usuario, object $revision, stdClass $in): object
    {
        if ($revision->estado !== 'abierta') {
            throw ApiException::validacion(__('La revisión ya está cerrada.'), ['resolucion' => [__('Revisión cerrada.')]]);
        }
        $eventoId = null;
        if ($in->resolucion === 'reaplicada') {
            if (! isset($in->correccion) || ! $in->correccion instanceof stdClass || $revision->entidad_tipo !== 'evento') {
                throw ApiException::validacion(__('Reaplicar exige la corrección a registrar.'), ['correccion' => [__('Obligatoria al reaplicar.')]]);
            }
            $enRevision = Evento::query()->findOrFail($revision->entidad_id);
            $vigente = Evento::query()->where('evento_raiz_id', $enRevision->evento_raiz_id)->where('en_revision', false)->orderByDesc('version')->firstOrFail();
            $in->correccion->motivo = trim(($in->correccion->motivo ?? $in->motivo).' (revisión '.$revision->id.')');
            $eventoId = $this->corregir->handle($est, $usuario, $vigente, $in->correccion, false)->evento->id;
        }

        DB::transaction(function () use ($est, $usuario, $revision, $in, $eventoId) {
            DB::table('revisiones')->where('id', $revision->id)->update([
                'estado' => $in->resolucion, 'resuelta_por' => $usuario->id, 'resuelta_en' => now(),
                'resolucion_motivo' => $in->motivo, 'resolucion_evento_id' => $eventoId, 'updated_at' => now(),
            ]);
            $this->registrarCambio->handle($est->id, 'revision', $revision->id, cadena: Cadena::bloquearDeEstablecimiento($est->id));
        });

        return DB::table('revisiones')->where('id', $revision->id)->first();
    }
}
