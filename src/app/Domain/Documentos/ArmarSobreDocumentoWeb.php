<?php

namespace App\Domain\Documentos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * La web no hashea (api-v1 A3): el servidor arma `tropilla.documento_version/1` con `creado_en` = ahora (ms, UTC), sin
 * `dispositivo_id`. Vínculos ordenados por (tipo, id); campos `null` se omiten (el canonicalizador los descarta igual).
 */
final class ArmarSobreDocumentoWeb
{
    /** @param array<string, mixed> $in DocumentoInput validado */
    public static function alta(Establecimiento $est, User $autor, array $in): stdClass
    {
        $tipo = DB::table('tipos_documento')->where('codigo', $in['tipo'])->first();

        return self::sobre([
            'id' => $in['version_id'] ?? (string) Str::uuid7(), 'documento_id' => $in['id'] ?? (string) Str::uuid7(), 'establecimiento_id' => $est->id,
            'version' => 1, 'accion' => 'alta', 'tipo' => $in['tipo'], 'privacidad' => $in['privacidad'] ?? $tipo?->privacidad_default ?? 'general',
            'titulo' => $in['titulo'], 'emisor' => $in['emisor'] ?? null, 'emitido_en' => $in['emitido_en'] ?? null,
            'vence_en' => $in['vence_en'] ?? self::venceDefault($tipo, $in['emitido_en'] ?? null), 'vinculos' => $in['vinculos'] ?? [], 'archivos' => $in['archivos'],
        ], $autor);
    }

    /**
     * Versión v+1 a partir de la vigente: lo que no viene se copia (una corrección de metadatos no obliga a reenviar archivos).
     *
     * @param  array<string, mixed>  $in  DocumentoVersionInput validado
     */
    public static function nuevaVersion(object $doc, User $autor, array $in): stdClass
    {
        $vigente = DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $doc->version_vigente)->where('en_revision', false)->first();
        $base = json_decode($vigente->sobre, true);
        $archivos = $in['archivos'] ?? DB::table('documento_version_archivos as va')->join('archivos as a', 'a.sha256', '=', 'va.archivo_sha256')
            ->where('va.documento_version_id', $vigente->id)->orderBy('va.orden')->get(['a.sha256', 'a.bytes', 'a.mime'])
            ->map(fn ($a) => ['sha256' => $a->sha256, 'bytes' => (int) $a->bytes, 'mime' => $a->mime])->all();

        return self::sobre([
            'id' => $in['id'] ?? (string) Str::uuid7(), 'documento_id' => $doc->id, 'establecimiento_id' => $doc->establecimiento_id,
            'version' => (int) $doc->version_vigente + 1, 'accion' => $in['accion'], 'hash_anterior' => $vigente->hash, 'motivo' => $in['motivo'],
            'tipo' => $in['tipo'] ?? $base['tipo'], 'privacidad' => $in['privacidad'] ?? $base['privacidad'], 'titulo' => $in['titulo'] ?? $base['titulo'],
            'emisor' => array_key_exists('emisor', $in) ? $in['emisor'] : ($base['emisor'] ?? null),
            'emitido_en' => array_key_exists('emitido_en', $in) ? $in['emitido_en'] : ($base['emitido_en'] ?? null),
            'vence_en' => array_key_exists('vence_en', $in) ? $in['vence_en'] : ($base['vence_en'] ?? null),
            'vinculos' => $in['vinculos'] ?? ($base['vinculos'] ?? []),
            'archivos' => $in['accion'] === 'revocacion' ? [] : $archivos,
        ], $autor);
    }

    /** @param array<string, mixed> $d */
    private static function sobre(array $d, User $autor): stdClass
    {
        $vinculos = array_map(fn ($v) => ['tipo' => $v['tipo'], 'id' => strtolower($v['id'])], (array) $d['vinculos']);
        usort($vinculos, fn ($a, $b) => strcmp($a['tipo']."\0".$a['id'], $b['tipo']."\0".$b['id']));
        $emisor = is_array($d['emisor'] ?? null) ? array_filter($d['emisor'], fn ($v) => $v !== null && $v !== '') : null;
        $arr = array_filter([
            'esquema' => 'tropilla.documento_version/1', 'id' => strtolower($d['id']), 'documento_id' => strtolower($d['documento_id']),
            'establecimiento_id' => $d['establecimiento_id'], 'version' => $d['version'], 'accion' => $d['accion'],
            'hash_anterior' => $d['hash_anterior'] ?? null, 'motivo' => $d['motivo'] ?? null, 'tipo' => $d['tipo'], 'privacidad' => $d['privacidad'],
            'titulo' => $d['titulo'], 'emisor' => $emisor ?: null, 'emitido_en' => $d['emitido_en'], 'vence_en' => $d['vence_en'],
            'vinculos' => $vinculos, 'archivos' => array_map(fn ($a) => ['sha256' => strtolower($a['sha256']), 'bytes' => (int) $a['bytes'], 'mime' => $a['mime']], (array) $d['archivos']),
            'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'autor_id' => $autor->id,
        ], fn ($v) => $v !== null);

        return json_decode(json_encode($arr), false);
    }

    private static function venceDefault(?object $tipo, ?string $emitido): ?string
    {
        return ($tipo?->vence_por_defecto_dias && $emitido) ? now()->parse($emitido)->addDays((int) $tipo->vence_por_defecto_dias)->toDateString() : null;
    }
}
