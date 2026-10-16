<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Sanidad\CompletarSobreWeb;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

/**
 * La web no canonicaliza ni hashea (api-v1 A3): el servidor arma el sobre `tropilla.evento/1` con
 * `origen: web`, `autor_id` = usuario, `creado_en` = ahora (ms, UTC) y sin `dispositivo_id`.
 * El input se lee del cuerpo crudo decodificado **con objetos** (para no convertir `{}` en `[]`).
 */
final class ArmarSobreWeb
{
    public static function alta(Establecimiento $est, User $autor, stdClass $in): stdClass
    {
        $s = new stdClass;
        $s->esquema = 'tropilla.evento/1';
        $s->id = isset($in->id) && is_string($in->id) ? strtolower($in->id) : (string) Str::uuid7();
        $s->establecimiento_id = $est->id;
        $s->evento_raiz_id = $s->id;
        $s->version = 1;
        $s->accion = 'alta';
        self::comunes($s, $est, $autor, $in);
        CompletarSobreWeb::completar($est, $s);

        return $s;
    }

    /** Corrección (con payload) o anulación (sin payload) de la versión `$base`. */
    public static function nuevaVersion(Establecimiento $est, User $autor, Evento $base, stdClass $in, bool $anulacion): stdClass
    {
        $sobreBase = CanonicalJson::decode($base->sobreCrudo());
        $s = new stdClass;
        $s->esquema = 'tropilla.evento/1';
        $s->id = isset($in->id) && is_string($in->id) ? strtolower($in->id) : (string) Str::uuid7();
        $s->establecimiento_id = $est->id;
        $s->evento_raiz_id = $base->evento_raiz_id;
        $s->version = $base->version + 1;
        $s->accion = $anulacion ? 'anulacion' : 'correccion';
        $s->corrige_evento_id = $base->id;
        $s->hash_anterior = $base->hash;
        $s->motivo = $in->motivo ?? null;
        $s->tipo = $base->tipo;
        $s->tipo_version = $in->tipo_version ?? $base->tipo_version;
        $s->sujetos = self::sujetos($in->sujetos ?? $sobreBase->sujetos);
        if (! $anulacion) {
            $s->payload = $in->payload ?? $sobreBase->payload;
        }
        $s->ocurrido_en = $in->ocurrido_en ?? $sobreBase->ocurrido_en;
        $s->creado_en = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $s->autor_id = $autor->id;
        $s->origen = 'web';
        foreach (['responsable', 'observaciones'] as $c) {
            $v = property_exists($in, $c) ? $in->{$c} : ($sobreBase->{$c} ?? null);
            if ($v !== null && ! $anulacion) {
                $s->{$c} = $v;
            }
        }
        if (! $anulacion && ! empty($in->adjuntos ?? $sobreBase->adjuntos ?? null)) {
            $s->adjuntos = $in->adjuntos ?? $sobreBase->adjuntos;
        }

        return $s;
    }

    private static function comunes(stdClass $s, Establecimiento $est, User $autor, stdClass $in): void
    {
        $s->tipo = $in->tipo;
        $s->tipo_version = $in->tipo_version ?? (TiposEvento::get((string) $in->tipo)?->version_vigente ?? 1);
        $s->sujetos = self::sujetos($in->sujetos);
        $s->payload = $in->payload;
        $s->ocurrido_en = $in->ocurrido_en;
        $s->creado_en = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $s->autor_id = $autor->id;
        $s->origen = 'web';
        foreach (['responsable', 'observaciones'] as $c) {
            if (isset($in->{$c})) {
                $s->{$c} = $in->{$c};
            }
        }
        if (! empty($in->adjuntos)) {
            $s->adjuntos = $in->adjuntos;
        }
    }

    /** `animal_ids` ordenados y sin repetidos (el canonicalizador no ordena arrays). */
    private static function sujetos(mixed $sujetos): mixed
    {
        if (! $sujetos instanceof stdClass) {
            return $sujetos;
        }
        $s = clone $sujetos;
        if (isset($s->animal_ids) && is_array($s->animal_ids)) {
            $ids = array_values(array_unique(array_map(fn ($x) => is_string($x) ? strtolower($x) : $x, $s->animal_ids)));
            sort($ids, SORT_STRING);
            $s->animal_ids = $ids;
        }

        return $s;
    }
}
