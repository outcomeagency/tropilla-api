<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schema `IntegridadRegistro`: huella, eslabón, seq y —cuando exista (TPA-40)— anclaje y prueba de inclusión. */
final class IntegridadResource
{
    /** @return array<string, mixed> */
    public static function deRegistro(int $registroId): array
    {
        $r = DB::table('registros_integridad')->where('id', $registroId)->first();
        $anclaje = null;
        $prueba = null;
        if (DB::getSchemaBuilder()->hasTable('anclaje_inclusiones')) {
            $inc = DB::table('anclaje_inclusiones as i')->join('anclajes as a', 'a.id', '=', 'i.anclaje_id')->where('i.registro_id', $registroId)
                ->orderByRaw("a.estado = 'confirmado' DESC")->orderByDesc('i.id')->first(['i.prueba', 'a.*']);
            if ($inc) {
                $prueba = json_decode($inc->prueba, true);
                $anclaje = ['id' => $inc->id, 'driver' => $inc->driver, 'red' => $inc->red, 'raiz' => $inc->raiz, 'estado' => $inc->estado,
                    'confirmado_en' => $inc->confirmado_en ? Formato::fechaHora(Carbon::parse($inc->confirmado_en)) : null,
                    'referencia' => $inc->referencia ? json_decode($inc->referencia, true) : null,
                    'recibo_url' => $inc->recibo_clave ? url("/api/v1/public/anclajes/{$inc->id}/recibo") : null];
            }
        }

        return [
            'sujeto_tipo' => $r->sujeto_tipo, 'sujeto_id' => $r->sujeto_id, 'huella' => $r->huella, 'seq' => (int) $r->seq,
            'eslabon_anterior' => $r->eslabon_anterior, 'eslabon' => $r->eslabon,
            'registrado_en' => Formato::fechaHora(Carbon::parse($r->registrado_en)),
            'confianza' => Confianza::deRegistro($registroId), 'anclaje' => $anclaje, 'prueba' => $prueba,
        ];
    }
}
