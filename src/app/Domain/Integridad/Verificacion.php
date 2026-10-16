<?php

namespace App\Domain\Integridad;

use App\Domain\Catalogos\Catalogos;
use App\Http\Resources\V1\Hacienda\IntegridadResource;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verificación pública por huella (TPA-41, sync-e-integridad §6.4): muestra huella, eslabón, cadena, anclaje y prueba —
 * **verificar ≠ ver**: el contenido privado no sale. El sobre completo solo se publica cuando el registro ya es público
 * (publicaciones de oferta y de perfil; T9). Eventos: tipo y fecha, autor solo por rol, establecimiento solo si su
 * perfil está publicado. Lenguaje: prueba **integridad**, nunca veracidad.
 */
final class Verificacion
{
    private const PUBLICOS = ['oferta_publicacion', 'perfil_publicacion'];

    private const VERIFICABLES = ['evento', 'oferta_publicacion', 'perfil_publicacion', 'documento_version', 'contrato_version'];

    /** @return array<string, mixed>|null */
    public static function deHuella(string $huella, string $locale): ?array
    {
        $r = DB::table('registros_integridad')->where('huella', $huella)->whereIn('sujeto_tipo', self::VERIFICABLES)->orderBy('id')->first();
        if ($r === null) {
            return null;
        }
        $est = DB::table('cadenas as c')->join('establecimientos as e', 'e.id', '=', 'c.establecimiento_id')->where('c.id', $r->cadena_id)->first(['e.id', 'e.nombre']);
        $perfil = $est ? DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->where('publicado', true)->first() : null;
        [$descripcion, $versiones, $vigente, $autorId, $sobre] = self::detalle($r, $locale);
        $rol = $autorId && $est ? DB::table('membresias')->where('establecimiento_id', $est->id)->where('usuario_id', $autorId)
            ->orderByRaw("estado = 'activa' DESC")->value('rol') : null;

        return [
            'huella' => $huella,
            'tipo' => $r->sujeto_tipo,
            'descripcion' => $descripcion,
            'registrado_en' => Formato::fechaHora(Carbon::parse($r->registrado_en)),
            'autor' => $autorId ? ['nombre' => null, 'rol' => $rol ?? 'usuario'] : null,
            'establecimiento' => $est && ($perfil || in_array($r->sujeto_tipo, self::PUBLICOS, true)) ? ['nombre' => $est->nombre, 'slug' => $perfil?->slug] : null,
            'versiones' => $versiones,
            'es_vigente' => $vigente,
            'confianza' => Confianza::deRegistro((int) $r->id),
            'integridad' => IntegridadResource::deRegistro((int) $r->id),
            'sobre' => in_array($r->sujeto_tipo, self::PUBLICOS, true) ? $sobre : null,
            'leyenda' => Leyenda::verificacion($locale),
        ];
    }

    /** @return array{0: string, 1: int, 2: bool, 3: ?string, 4: ?string} [descripción, versiones, vigente, autor, sobre] */
    private static function detalle(object $r, string $locale): array
    {
        $sobre = FuentesSobre::sobre($r->sujeto_tipo, $r->sujeto_id);
        $txt = ['evento' => ['es' => 'Registro de hacienda', 'en' => 'Herd record', 'pt' => 'Registro de rebanho'],
            'oferta_publicacion' => ['es' => 'Publicación de oferta', 'en' => 'Offer publication', 'pt' => 'Publicação de oferta'],
            'perfil_publicacion' => ['es' => 'Publicación del perfil', 'en' => 'Profile publication', 'pt' => 'Publicação do perfil']];

        return match ($r->sujeto_tipo) {
            'evento' => (function () use ($r, $locale, $sobre, $txt) {
                $e = DB::table('eventos')->where('id', $r->sujeto_id)->first();
                $tipo = Catalogos::nombre(DB::table('tipos_evento')->where('codigo', $e->tipo)->value('nombres'), $locale) ?? $e->tipo;
                $max = DB::table('eventos')->where('evento_raiz_id', $e->evento_raiz_id)->where('en_revision', false)->max('version');

                return [$txt['evento'][$locale].': '.$tipo.' · '.Carbon::parse($e->ocurrido_en)->toDateString().($e->version > 1 ? " (v{$e->version})" : ''),
                    (int) DB::table('eventos')->where('evento_raiz_id', $e->evento_raiz_id)->count(), ! $e->en_revision && (int) $max === (int) $e->version, $e->autor_id, $sobre];
            })(),
            'oferta_publicacion' => (function () use ($r, $locale, $sobre, $txt) {
                $p = DB::table('oferta_publicaciones')->where('id', $r->sujeto_id)->first();
                $o = DB::table('ofertas')->where('id', $p->oferta_id)->first();
                $titulo = (json_decode($p->contenido, true)['textos'][$locale]['titulo'] ?? null) ?: $o->codigo_publico;

                return [$txt['oferta_publicacion'][$locale].': '.$titulo." (v{$p->version})", (int) DB::table('oferta_publicaciones')->where('oferta_id', $o->id)->count(),
                    (int) $o->publicacion_vigente === (int) $p->version, $p->publicado_por, $sobre];
            })(),
            'perfil_publicacion' => (function () use ($r, $locale, $sobre, $txt) {
                $p = DB::table('perfil_publicaciones')->where('id', $r->sujeto_id)->first();
                $max = DB::table('perfil_publicaciones')->where('establecimiento_id', $p->establecimiento_id)->max('version');

                return [$txt['perfil_publicacion'][$locale]." (v{$p->version})", (int) $max, (int) $max === (int) $p->version, $p->publicado_por, $sobre];
            })(),
            // Documento: tipo y versión; el contenido (título, archivos) solo si está publicado en el perfil.
            'documento_version' => (function () use ($r, $locale) {
                $v = DB::table('documento_versiones')->where('id', $r->sujeto_id)->first();
                $d = DB::table('documentos')->where('id', $v->documento_id)->first();
                $tipo = Catalogos::nombre(DB::table('tipos_documento')->where('codigo', $v->tipo_codigo)->value('nombres'), $locale) ?? $v->tipo_codigo;
                $txt = ['es' => 'Documento', 'en' => 'Document', 'pt' => 'Documento'][$locale];
                $titulo = $d->visibilidad === 'perfil' ? ': '.$v->titulo : '';

                return ["{$txt} ({$tipo}){$titulo} · v{$v->version}", (int) DB::table('documento_versiones')->where('documento_id', $d->id)->count(),
                    ! $v->en_revision && (int) $d->version_vigente === (int) $v->version, $v->autor_id, null];
            })(),
            default => [$r->sujeto_tipo, 1, true, null, null],
        };
    }

    /** @return array<string, mixed> VerificacionArchivo */
    public static function deArchivo(string $sha256, string $locale): array
    {
        $doc = null;
        if (DB::getSchemaBuilder()->hasTable('documento_version_archivos')) {
            $doc = DB::table('documento_version_archivos as a')->join('documento_versiones as v', 'v.id', '=', 'a.documento_version_id')
                ->join('documentos as d', 'd.id', '=', 'v.documento_id')->where('a.archivo_sha256', $sha256)
                ->where('d.visibilidad', 'perfil')->orderByDesc('v.version')->first(['v.*']);
        }
        $msg = [
            'si' => ['es' => 'Coincide con una versión registrada en TROPILLA.', 'en' => 'It matches a version registered in TROPILLA.', 'pt' => 'Coincide com uma versão registrada na TROPILLA.'],
            'no' => ['es' => 'No coincide: el archivo cambió o no fue registrado en TROPILLA.', 'en' => 'No match: the file changed or was not registered in TROPILLA.', 'pt' => 'Não coincide: o arquivo mudou ou não foi registrado na TROPILLA.'],
        ];
        if ($doc === null) {
            return ['coincide' => false, 'documento' => null, 'mensaje' => $msg['no'][$locale]];
        }

        return ['coincide' => true, 'mensaje' => $msg['si'][$locale], 'documento' => [
            'titulo' => $doc->titulo, 'version' => (int) $doc->version, 'registrado_en' => Formato::fechaHora(Carbon::parse($doc->registrado_en)),
            'confianza' => Confianza::deRegistro((int) $doc->registro_integridad_id), 'verificacion_url' => config('tropilla.web_url').'/v/'.$doc->hash,
        ]];
    }
}
