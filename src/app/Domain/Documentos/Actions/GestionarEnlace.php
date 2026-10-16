<?php

namespace App\Domain\Documentos\Actions;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\Archivos;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Integridad\Confianza;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compartir un documento por enlace temporal (TPA-38) sin publicarlo en el perfil. El token (256 bits) solo vive en la URL;
 * se guarda su SHA-256. Vence (obligatorio, máx. 90 días) y se revoca. Fiscal: se puede compartir (acción explícita del
 * dueño/admin, con aviso), nunca publicar. El acceso público muestra confianza y verificación; cada visita queda registrada.
 */
final class GestionarEnlace
{
    public const MAX_DIAS = 90;

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @return array<string, mixed> Enlace (+ url con el token, solo acá) */
    public function crear(object $doc, User $usuario, Carbon $expira, ?int $version): array
    {
        if ($doc->estado === 'revocado') {
            throw ApiException::reglaBloqueante(__('Un documento revocado no se comparte.'));
        }
        if ($expira->lte(now()) || $expira->gt(now()->addDays(self::MAX_DIAS))) {
            throw ApiException::validacion(__('El enlace vence entre ahora y :d días.', ['d' => self::MAX_DIAS]), ['expira_en' => [__('Entre ahora y :d días.', ['d' => self::MAX_DIAS])]]);
        }
        if ($version !== null && ! DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $version)->where('en_revision', false)->exists()) {
            throw ApiException::validacion(__('Esa versión no existe.'), ['version' => [__('Esa versión no existe.')]]);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = (string) Str::uuid7();
        DB::table('enlaces_compartidos')->insert([
            'id' => $id, 'documento_id' => $doc->id, 'establecimiento_id' => $doc->establecimiento_id, 'version' => $version,
            'token_hash' => hash('sha256', $token), 'expira_en' => $expira, 'creado_por' => $usuario->id, 'created_at' => now(),
        ]);
        $this->auditoria->handle($doc->establecimiento_id, $usuario->id, 'documento', $doc->id, 'compartir', null, ['enlace_id' => $id, 'expira_en' => $expira->toIso8601String(), 'version' => $version]);

        return [...self::enlace(DB::table('enlaces_compartidos')->where('id', $id)->first()), 'url' => config('tropilla.web_url').'/e/'.$token,
            'aviso' => $doc->privacidad === 'fiscal' ? __('Es un documento fiscal: quien tenga el enlace lo puede ver hasta que venza o lo revoques.') : null];
    }

    public function revocar(object $enlace, User $usuario): void
    {
        if ($enlace->revocado_en !== null) {
            return;
        }
        DB::table('enlaces_compartidos')->where('id', $enlace->id)->update(['revocado_en' => now(), 'revocado_por' => $usuario->id]);
        $this->auditoria->handle($enlace->establecimiento_id, $usuario->id, 'documento', $enlace->documento_id, 'revocar_enlace', null, ['enlace_id' => $enlace->id]);
    }

    /** @return array<string, mixed> Enlace (sin token) */
    public static function enlace(object $e): array
    {
        return ['id' => $e->id, 'expira_en' => Formato::fechaHora(Carbon::parse($e->expira_en)),
            'revocado_en' => $e->revocado_en ? Formato::fechaHora(Carbon::parse($e->revocado_en)) : null,
            'version' => $e->version === null ? null : (int) $e->version, 'accesos' => (int) $e->accesos_count,
            'created_at' => Formato::fechaHora(Carbon::parse($e->created_at)), 'url' => null];
    }

    /**
     * Acceso público por token: 404 si no existe, 410 `enlace_vencido` si venció o fue revocado. Registra la visita.
     *
     * @return array<string, mixed> EnlacePublico
     */
    public function abrir(string $token, ?string $ip, ?string $userAgent, string $locale): array
    {
        $e = DB::table('enlaces_compartidos')->where('token_hash', hash('sha256', $token))->first() ?? throw ApiException::noEncontrado();
        if ($e->revocado_en !== null || Carbon::parse($e->expira_en)->isPast()) {
            throw new ApiException(410, 'enlace_vencido', __('El enlace venció o fue revocado.'));
        }
        DB::transaction(function () use ($e, $ip, $userAgent) {
            DB::table('enlace_accesos')->insert(['enlace_id' => $e->id, 'accedido_en' => now(),
                'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null, 'user_agent' => $userAgent ? mb_substr($userAgent, 0, 300) : null]);
            DB::table('enlaces_compartidos')->where('id', $e->id)->increment('accesos_count');
        });

        $d = DB::table('documentos')->where('id', $e->documento_id)->first();
        $v = DB::table('documento_versiones')->where('documento_id', $d->id)->where('version', $e->version ?? $d->version_vigente)->where('en_revision', false)->first();
        $hoy = Carbon::now((string) DB::table('establecimientos')->where('id', $d->establecimiento_id)->value('zona_horaria'))->toDateString();
        $archivos = DB::table('documento_version_archivos as va')->join('archivos as a', 'a.sha256', '=', 'va.archivo_sha256')
            ->where('va.documento_version_id', $v->id)->orderBy('va.orden')->get(['a.*'])
            ->map(fn ($a) => ['sha256' => $a->sha256, 'bytes' => $a->bytes === null ? null : (int) $a->bytes, 'mime' => $a->mime, 'estado' => $a->estado, 'url' => Archivos::url($a->sha256)])->all();

        return [
            'documento' => [
                'titulo' => $v->titulo, 'tipo' => (string) Catalogos::nombre(DB::table('tipos_documento')->where('codigo', $v->tipo_codigo)->value('nombres'), $locale),
                'emisor_declarado' => $v->emisor_nombre, 'emitido_en' => $v->emitido_en, 'vence_en' => $v->vence_en,
                'estado' => (int) $v->version === (int) $d->version_vigente ? ConsultasDocumentos::estado($d, $hoy) : 'reemplazado',
                'confianza' => Confianza::deRegistro((int) $v->registro_integridad_id), 'verificacion_url' => config('tropilla.web_url').'/v/'.$v->hash,
            ],
            'version' => (int) $v->version,
            'archivos' => $archivos,
            'expira_en' => Formato::fechaHora(Carbon::parse($e->expira_en)),
            'establecimiento' => ['nombre' => (string) DB::table('establecimientos')->where('id', $d->establecimiento_id)->value('nombre')],
            'leyenda' => Leyenda::texto($locale).' '.Leyenda::verificacion($locale),
        ];
    }
}
