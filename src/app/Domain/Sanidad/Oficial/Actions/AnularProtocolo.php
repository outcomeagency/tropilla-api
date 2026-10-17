<?php

namespace App\Domain\Sanidad\Oficial\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\CorregirEvento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Un protocolo registrado no se edita: se anula con motivo (TPA-66). Anula también sus `sanidad.prueba_diagnostica` con
 * una versión nueva de cada evento (el historial queda) y el proyector recalcula reactores. Para corregir: anular y cargar de nuevo.
 */
final class AnularProtocolo
{
    public function __construct(private readonly CorregirEvento $corregir, private readonly RegistrarAuditoria $auditoria) {}

    public function handle(Establecimiento $est, User $u, object $p, string $motivo): object
    {
        if ($p->estado === 'anulado') {
            return $p;
        }
        if (DB::table('estatus_sanitarios')->where('protocolo_id', $p->id)->where('estado', 'vigente')->exists()) {
            throw new ApiException(409, 'en_uso', __('El protocolo respalda el estatus vigente: anulá o reemplazá primero el estatus.'));
        }

        return DB::transaction(function () use ($est, $u, $p, $motivo) {
            $p = DB::table('protocolos_sanitarios')->where('id', $p->id)->lockForUpdate()->first();
            foreach (json_decode($p->evento_ids, true) as $eventoId) {
                $raiz = Evento::query()->where('id', $eventoId)->value('evento_raiz_id');
                $vigente = Evento::query()->where('evento_raiz_id', $raiz)->where('en_revision', false)->orderByDesc('version')->first();
                if ($vigente !== null && $vigente->accion !== 'anulacion') {
                    $this->corregir->handle($est, $u, $vigente, (object) ['motivo' => mb_substr(__('Protocolo anulado: :m', ['m' => $motivo]), 0, 500)], true);
                }
            }
            DB::table('protocolos_sanitarios')->where('id', $p->id)->update([
                'estado' => 'anulado', 'anulado_por' => $u->id, 'anulado_en' => now(), 'anulado_motivo' => $motivo, 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $u->id, 'protocolo_sanitario', $p->id, 'anulacion', ['estado' => 'vigente'], ['estado' => 'anulado'], $motivo);

            return DB::table('protocolos_sanitarios')->where('id', $p->id)->first();
        });
    }
}
