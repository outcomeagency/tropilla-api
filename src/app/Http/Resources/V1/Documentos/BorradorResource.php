<?php

namespace App\Http\Resources\V1\Documentos;

use App\Domain\Documentos\Borradores\Borradores;
use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Documentos\Fotos;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** `BorradorDocumento` (TPA-61). `detalle`: suma el historial auditable (extracciones y correcciones). */
final class BorradorResource
{
    public const AVISO = 'Transcripción asistida: revisá cada dato contra el original. No es una presentación oficial ante SENASA.';

    /** @return array<string, mixed> */
    public static function make(object $b, bool $detalle = false): array
    {
        $campos = json_decode($b->campos, true);
        $shas = Borradores::paginas($b);
        $estados = DB::table('archivos')->whereIn('sha256', $shas)->pluck('estado', 'sha256');
        $fotos = collect(Fotos::lista($shas, false))->keyBy('sha256');
        $ext = $b->extraccion_vigente === null ? null
            : DB::table('borrador_extracciones')->where('borrador_id', $b->id)->where('version', $b->extraccion_vigente)->first(['version', 'esquema', 'proveedor', 'modelo', 'created_at']);
        $fecha = fn ($t) => $t === null ? null : Formato::fechaHora(Carbon::parse($t));

        $out = [
            'id' => $b->id, 'documento_id' => $b->documento_id, 'documento_version_id' => $b->documento_version_id, 'familia' => $b->familia,
            'esquema' => $b->esquema, 'estado' => $b->estado, 'error' => $b->error, 'intentos' => (int) $b->intentos,
            'paginas' => array_map(fn ($sha, $i) => ['numero' => $i + 1, 'sha256' => $sha, 'estado' => $estados[$sha] ?? 'pendiente', 'foto' => $fotos->get($sha)], $shas, array_keys($shas)),
            'campos' => $campos, 'pendientes' => Campos::pendientes($campos), 'advertencias' => json_decode($b->advertencias, true),
            'extraccion' => $ext === null ? null : ['version' => (int) $ext->version, 'esquema' => $ext->esquema, 'proveedor' => $ext->proveedor, 'modelo' => $ext->modelo, 'creada_en' => $fecha($ext->created_at)],
            'evento_ids' => json_decode($b->evento_ids, true), 'aviso' => __(self::AVISO), 'row_version' => (int) $b->row_version,
            'creado_por' => $b->creado_por, 'created_at' => $fecha($b->created_at), 'updated_at' => $fecha($b->updated_at),
            'confirmado_por' => $b->confirmado_por, 'confirmado_en' => $fecha($b->confirmado_en), 'descartado_en' => $fecha($b->descartado_en),
        ];
        if ($detalle) {
            $out['historial'] = [
                'extracciones' => DB::table('borrador_extracciones')->where('borrador_id', $b->id)->orderBy('version')->get()->map(fn ($e) => [
                    'version' => (int) $e->version, 'esquema' => $e->esquema, 'proveedor' => $e->proveedor, 'modelo' => $e->modelo,
                    'tokens_entrada' => $e->tokens_entrada, 'tokens_salida' => $e->tokens_salida, 'creada_en' => $fecha($e->created_at),
                ])->all(),
                'correcciones' => DB::table('borrador_correcciones')->where('borrador_id', $b->id)->orderBy('id')->get()->map(fn ($c) => [
                    'ruta' => $c->ruta, 'estado' => $c->estado, 'valor_anterior' => json_decode((string) $c->valor_anterior, true),
                    'valor' => json_decode((string) $c->valor, true), 'usuario_id' => $c->usuario_id, 'extraccion_version' => (int) $c->extraccion_version, 'creada_en' => $fecha($c->created_at),
                ])->all(),
            ];
        }

        return $out;
    }
}
