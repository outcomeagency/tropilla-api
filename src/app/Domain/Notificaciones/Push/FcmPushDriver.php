<?php

namespace App\Domain\Notificaciones\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 con cuenta de servicio (JWT RS256 → access token OAuth, cacheado 50 min). Sin SDK.
 * `UNREGISTERED` / 404 → token inválido (devuelve false para que se borre del dispositivo).
 */
final class FcmPushDriver implements PushDriver
{
    /** @param array{project_id: string, client_email: string, private_key: string, token_uri?: string} $credenciales */
    public function __construct(private readonly array $credenciales) {}

    public static function desdeArchivo(?string $ruta): self
    {
        $c = $ruta && is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (! is_array($c) || empty($c['project_id']) || empty($c['client_email']) || empty($c['private_key'])) {
            throw new RuntimeException('FCM: faltan las credenciales de la cuenta de servicio (TROPILLA_FCM_CREDENCIALES).');
        }

        return new self($c);
    }

    public function enviar(string $token, string $titulo, string $cuerpo, array $datos): bool
    {
        $r = Http::timeout(10)->withToken($this->accessToken())->post("https://fcm.googleapis.com/v1/projects/{$this->credenciales['project_id']}/messages:send", [
            'message' => ['token' => $token, 'notification' => ['title' => $titulo, 'body' => $cuerpo], 'data' => array_map('strval', $datos),
                'android' => ['priority' => 'high']],
        ]);
        if ($r->status() === 404 || str_contains((string) $r->body(), 'UNREGISTERED')) {
            return false;
        }
        if (! $r->successful()) {
            throw new RuntimeException('FCM respondió '.$r->status());
        }

        return true;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:token:'.$this->credenciales['client_email'], 3000, function () {
            $uri = $this->credenciales['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $ahora = time();
            $firmar = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $this->credenciales['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $uri, 'iat' => $ahora, 'exp' => $ahora + 3600,
            ]));
            if (! openssl_sign($firmar, $firma, $this->credenciales['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM: no se pudo firmar el JWT.');
            }
            $r = Http::asForm()->timeout(10)->post($uri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $firmar.'.'.$b64($firma)]);

            return (string) ($r->json('access_token') ?? throw new RuntimeException('FCM: sin access token.'));
        });
    }
}
