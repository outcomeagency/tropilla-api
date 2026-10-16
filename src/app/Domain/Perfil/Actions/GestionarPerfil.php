<?php

namespace App\Domain\Perfil\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Perfil\Services\Perfiles;
use App\Domain\Perfil\Services\PerfilPublicoBuilder;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Perfil público (TPA-50): edición (row_version, auditada), vista previa obligatoria antes de la 1ª publicación,
 * publicar = snapshot hasheado en `perfil_publicaciones` (lo que se mostró es demostrable), despublicar,
 * congelar/descongelar secciones derivadas. Cambiar el slug deja el viejo como redirección (301).
 */
final class GestionarPerfil
{
    public function __construct(private readonly RegistrarAuditoria $auditoria, private readonly RegistrarEnCadena $cadena) {}

    /** @param array<string, mixed> $d */
    public function editar(Establecimiento $est, User $u, array $d): object
    {
        return DB::transaction(function () use ($est, $u, $d) {
            $p = Perfiles::asegurar($est);
            $p = DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->lockForUpdate()->first();
            if ((int) $d['row_version'] !== (int) $p->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => (int) $p->row_version]);
            }
            $cambios = [];
            foreach (['en_directorio', 'asociaciones_visibles', 'ubicacion_publica'] as $c) {
                if (array_key_exists($c, $d) && $d[$c] !== null) {
                    $cambios[$c] = $d[$c];
                }
            }
            if (array_key_exists('en_directorio', $cambios)) {
                $cambios['indexable'] = (bool) $cambios['en_directorio'];
            }
            if (isset($d['textos'])) {
                $cambios['textos'] = json_encode(array_replace_recursive(json_decode($p->textos, true) ?: [], $d['textos']), JSON_UNESCAPED_UNICODE);
            }
            if (isset($d['contacto_visible'])) {
                $cambios['contacto_visible'] = json_encode([...(json_decode($p->contacto_visible, true) ?: []), ...array_map('boolval', $d['contacto_visible'])]);
            }
            if (isset($d['secciones'])) {
                $actual = json_decode($p->secciones, true) ?: Perfiles::seccionesDefault();
                foreach ($d['secciones'] as $s => $conf) {
                    $actual[$s] = [...($actual[$s] ?? []), ...array_intersect_key((array) $conf, array_flip(['activa', 'modo']))];
                }
                $cambios['secciones'] = json_encode($actual);
            }
            if (isset($d['fotos'])) { // bugfix TPA-54: se validaba pero no se guardaba; la primera es la portada
                $cambios['fotos'] = '{'.implode(',', array_values($d['fotos'])).'}';
            }
            if (isset($d['idiomas'])) {
                $cambios['idiomas'] = '{'.implode(',', array_unique($d['idiomas'])).'}';
            }
            if (! empty($d['slug']) && strtolower($d['slug']) !== strtolower($p->slug)) {
                if (! $this->slugDisponible($d['slug'], $est->id)) {
                    throw ApiException::validacion(__('Ese nombre de enlace ya está en uso.'), ['slug' => [__('No disponible.')]]);
                }
                DB::table('perfil_slugs_historicos')->updateOrInsert(['slug' => $p->slug], ['establecimiento_id' => $est->id, 'hasta' => now()]);
                DB::table('perfil_slugs_historicos')->where('slug', strtolower($d['slug']))->delete();
                $cambios['slug'] = strtolower($d['slug']);
            }
            $cambios['row_version'] = $p->row_version + 1;
            $cambios['updated_at'] = now();
            DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update($cambios);
            $this->recalcularCompletitud($est->id);
            $this->auditoria->handle($est->id, $u->id, 'perfil', $est->id, 'editar', null, array_diff_key($cambios, ['updated_at' => 1]));

            return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
        });
    }

    public function slugDisponible(string $slug, string $establecimientoId): bool
    {
        $slug = strtolower($slug);
        if (! preg_match('/^[a-z0-9-]{3,60}$/', $slug)) {
            return false;
        }

        return ! DB::table('perfiles_publicos')->where('slug', $slug)->where('establecimiento_id', '<>', $establecimientoId)->exists()
            && ! DB::table('perfil_slugs_historicos')->where('slug', $slug)->where('establecimiento_id', '<>', $establecimientoId)->exists();
    }

    public function aceptarPreview(Establecimiento $est, User $u): object
    {
        Perfiles::asegurar($est);
        DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update(['preview_aceptada_en' => now(), 'updated_at' => now()]);
        $this->auditoria->handle($est->id, $u->id, 'perfil', $est->id, 'editar', null, ['preview_aceptada' => true]);

        return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
    }

    public function publicar(Establecimiento $est, User $u): object
    {
        return DB::transaction(function () use ($est, $u) {
            $cad = Cadena::bloquearDeEstablecimiento($est->id);
            $p = Perfiles::asegurar($est);
            if ($p->preview_aceptada_en === null) {
                throw new ApiException(422, 'preview_requerida', __('Revisá la vista previa antes de publicar.'));
            }
            $u->emailVerificado() || throw ApiException::reglaBloqueante(__('Verificá tu email antes de publicar.'));
            $version = ((int) $p->publicacion_vigente) + 1;
            $id = (string) Str::uuid7();
            $contenido = PerfilPublicoBuilder::armar($p, 'es');
            unset($contenido['seo'], $contenido['actualizado_en']);
            $sobre = json_decode(json_encode(['esquema' => 'tropilla.perfil_publicacion/1', 'id' => $id, 'establecimiento_id' => $est->id, 'version' => $version,
                'contenido_publico' => $contenido, 'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'autor_id' => $u->id, 'origen' => 'web'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
            $huella = CanonicalJson::huella($sobre);
            $reg = $this->cadena->handle($cad, 'perfil_publicacion', $id, $huella);
            DB::table('perfil_publicaciones')->insert(['id' => $id, 'establecimiento_id' => $est->id, 'version' => $version,
                'contenido' => json_encode($sobre->contenido_publico, JSON_UNESCAPED_UNICODE), 'sobre' => json_encode($sobre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'hash' => $huella, 'publicado_por' => $u->id, 'registro_integridad_id' => $reg->id, 'created_at' => now()]);
            DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update([
                'publicado' => true, 'publicado_en' => now(), 'primera_publicacion_en' => $p->primera_publicacion_en ?? now(),
                'publicacion_vigente' => $version, 'updated_at' => now(),
            ]);
            $this->recalcularCompletitud($est->id);
            $this->auditoria->handle($est->id, $u->id, 'perfil', $est->id, 'publicar', null, ['version' => $version, 'huella' => $huella]);

            return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
        });
    }

    public function despublicar(Establecimiento $est, User $u): object
    {
        Perfiles::asegurar($est);
        DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update(['publicado' => false, 'updated_at' => now()]);
        $this->auditoria->handle($est->id, $u->id, 'perfil', $est->id, 'despublicar');

        return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
    }

    public function congelar(Establecimiento $est, User $u, string $seccion, bool $congelar): object
    {
        if (! in_array($seccion, PerfilPublicoBuilder::SECCIONES, true)) {
            throw ApiException::noEncontrado(__('Sección desconocida.'));
        }
        $p = Perfiles::asegurar($est);
        $secciones = json_decode($p->secciones, true) ?: Perfiles::seccionesDefault();
        $snapshot = null;
        if ($congelar) {
            $prod = DB::table('productores')->where('id', $est->productor_id)->first();
            $snapshot = PerfilPublicoBuilder::seccion($seccion, $p, DB::table('establecimientos')->where('id', $est->id)->first(), $prod,
                (json_decode($p->textos, true) ?: [])['es'] ?? [], 'es', $secciones[$seccion] ?? []);
        }
        $secciones[$seccion] = [...($secciones[$seccion] ?? ['activa' => true]), 'congelada_en' => $congelar ? now()->toIso8601ZuluString() : null, 'snapshot' => $snapshot];
        DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update(['secciones' => json_encode($secciones, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $this->auditoria->handle($est->id, $u->id, 'perfil', $est->id, 'editar', null, ['seccion' => $seccion, 'congelada' => $congelar]);

        return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
    }

    private function recalcularCompletitud(string $establecimientoId): void
    {
        $p = DB::table('perfiles_publicos')->where('establecimiento_id', $establecimientoId)->first();
        [$pct] = PerfilPublicoBuilder::completitud($p);
        DB::table('perfiles_publicos')->where('establecimiento_id', $establecimientoId)->update(['completitud' => $pct]);
    }
}
