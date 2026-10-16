<?php

namespace App\Domain\Comercial\Actions;

use App\Domain\Comercial\Notifications\NuevaConsultaNotification;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Contacto sin cuenta (TPA-55): la consulta llega a la bandeja del productor (TPA-49) con el idioma del comprador.
 * Anti-spam: honeypot `website` (se acepta en silencio y queda como `spam`), captcha Turnstile y 3/h por IP
 * (rate limiter `consultas`). Destino por `oferta_codigo` (oferta publicada) o `establecimiento_slug` (perfil publicado).
 */
final class RecibirConsultaPublica
{
    /**
     * @param  array<string, mixed>  $d  ConsultaPublicaInput validado
     */
    public function handle(array $d, ?string $ip, ?string $userAgent, ?User $usuario): void
    {
        [$estId, $origen, $ofertaId] = $this->destino($d);
        $spam = ! empty($d['website']);
        $score = $spam ? null : $this->captcha((string) $d['captcha_token'], $ip);

        $id = (string) Str::uuid7();
        DB::table('consultas')->insert([
            'id' => $id, 'establecimiento_id' => $estId, 'origen' => $origen, 'oferta_id' => $ofertaId,
            'nombre' => $d['nombre'], 'empresa' => $d['empresa'] ?? null, 'pais' => strtoupper($d['pais']), 'email' => $d['email'] ?? null,
            'telefono' => $d['telefono'] ?? null, 'tipo_comprador' => $d['tipo_comprador'], 'volumen' => $d['volumen'] ?? null,
            'mensaje' => $d['mensaje'], 'idioma' => $d['idioma'], 'usuario_id' => $usuario?->id, 'estado' => $spam ? 'spam' : 'nueva',
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null, 'user_agent' => $userAgent ? mb_substr($userAgent, 0, 300) : null,
            'captcha_score' => $score, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($spam) {
            return;
        }

        $destinatarios = User::query()->whereIn('id', DB::table('membresias')->where('establecimiento_id', $estId)->where('estado', 'activa')
            ->whereIn('rol', ['dueno', 'admin'])->pluck('usuario_id'))->whereNotNull('email')->get();
        Notification::send($destinatarios, new NuevaConsultaNotification($estId, $id, $d['nombre'], $d['pais'], $d['idioma']));
        // In-app + push (TPA-53): sin datos personales en el aviso (el detalle se ve en el panel).
        app(Notificador::class)->aRoles($estId, ['dueno', 'admin'], 'consulta_nueva', 'Nueva consulta de un comprador',
            "Desde {$d['pais']} (idioma: {$d['idioma']}). Respondé desde el panel.", ['consulta_id' => $id, 'ruta' => "/comercial/consultas/{$id}"], "consulta:{$id}");
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function destino(array $d): array
    {
        if (! empty($d['oferta_codigo'])) {
            $o = DB::table('ofertas')->where('codigo_publico', strtoupper($d['oferta_codigo']))->whereIn('estado', ['publicada', 'reservada'])->first();
            if ($o === null) {
                throw ApiException::noEncontrado(__('La oferta no existe o ya no está disponible.'));
            }

            return [$o->establecimiento_id, 'oferta', $o->id];
        }
        if (! empty($d['establecimiento_slug'])) {
            $p = DB::table('perfiles_publicos')->where('slug', $d['establecimiento_slug'])->where('publicado', true)->first();
            if ($p === null) {
                throw ApiException::noEncontrado(__('El perfil no existe.'));
            }

            return [$p->establecimiento_id, 'perfil', null];
        }
        if (! empty($d['contrato_codigo'])) {
            throw ApiException::noEncontrado(__('El contrato no existe.')); // contratos a futuro: TPA-56
        }
        throw ApiException::validacion(__('Indicá a quién va la consulta.'), ['establecimiento_slug' => [__('Falta el destino (perfil u oferta).')]]);
    }

    /** Cloudflare Turnstile. Sin `TURNSTILE_SECRET` (local/testing) se acepta cualquier token no vacío. */
    private function captcha(string $token, ?string $ip): ?float
    {
        $secreto = config('services.turnstile.secret');
        if (! $secreto) {
            if (app()->environment('production')) {
                throw new ApiException(503, 'mantenimiento', __('El formulario no está disponible.'));
            }

            return null;
        }
        try {
            $r = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secreto, 'response' => $token, 'remoteip' => $ip]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile no respondió', ['error' => $e->getMessage()]);
            throw new ApiException(503, 'mantenimiento', __('No pudimos validar el captcha. Probá de nuevo.'));
        }
        if (! $r->json('success')) {
            throw ApiException::validacion(__('No pudimos validar que sos una persona.'), ['captcha_token' => [__('Captcha inválido.')]]);
        }

        return 1.0;
    }
}
