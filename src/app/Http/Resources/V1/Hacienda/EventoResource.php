<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Evento`: una versión con su huella, seq (eslabón de la cadena) y `confianza`.
 * El autor se muestra por nombre y rol; la ubicación de carga (privada) no sale nunca.
 *
 * @property Evento $resource
 */
class EventoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $sobre = json_decode($e->getRawOriginal('sobre') ?? $e->sobreCrudo(), true);
        $vigente = DB::table('eventos')->where('evento_raiz_id', $e->evento_raiz_id)->where('en_revision', false)->max('version');
        $seq = DB::table('registros_integridad')->where('id', $e->registro_integridad_id)->value('seq');
        $rol = Membresia::query()->where('establecimiento_id', $e->establecimiento_id)->where('usuario_id', $e->autor_id)
            ->orderByRaw("estado = 'activa' DESC")->value('rol');

        return [
            'id' => $e->id,
            'establecimiento_id' => $e->establecimiento_id,
            'evento_raiz_id' => $e->evento_raiz_id,
            'version' => $e->version,
            'accion' => $e->accion,
            'corrige_evento_id' => $e->corrige_evento_id,
            'motivo' => $e->motivo,
            'en_revision' => $e->en_revision,
            'vigente' => ! $e->en_revision && $e->version === (int) $vigente,
            'tipo' => $e->tipo,
            'tipo_version' => $e->tipo_version,
            'sujetos' => (object) $sobre['sujetos'],
            'payload' => (object) ($sobre['payload'] ?? []),
            'ocurrido_en' => $sobre['ocurrido_en'],
            'ocurrido_precision' => $e->ocurrido_precision,
            'creado_en' => Formato::fechaHora($e->creado_en),
            'registrado_en' => Formato::fechaHora($e->registrado_en),
            'autor' => ['id' => $e->autor_id, 'nombre' => $e->autor?->nombre ?? '', 'rol' => $rol instanceof \BackedEnum ? $rol->value : $rol],
            'dispositivo_id' => $e->dispositivo_id,
            'origen' => $e->origen,
            'responsable' => $e->responsable,
            'observaciones' => $sobre['observaciones'] ?? null,
            'adjuntos' => array_map(fn ($h) => ['sha256' => $h, 'estado' => DB::getSchemaBuilder()->hasTable('archivos') ? (DB::table('archivos')->where('sha256', $h)->value('estado') ?? 'pendiente') : 'pendiente', 'url' => null], PgTextArray::parse($e->getRawOriginal('adjuntos'))),
            'huella' => $e->hash,
            'hash_anterior' => $e->hash_anterior,
            'seq' => (int) $seq,
            'confianza' => Confianza::deRegistro($e->registro_integridad_id),
            'observaciones_validacion' => PgTextArray::parse($e->getRawOriginal('observaciones_validacion')),
        ];
    }
}
