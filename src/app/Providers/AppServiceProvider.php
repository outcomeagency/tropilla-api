<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // api-v1.md §5: login/registro 5/min/IP; resto 300/min/usuario.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by('auth|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Token web: vencimiento deslizante de N días (se extiende como mucho una vez por hora).
        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event) {
            $token = $event->token;
            if ($token->expires_at === null || ! in_array('web', $token->abilities ?? [], true)) {
                return;
            }
            $nuevo = now()->addDays(config('tropilla.auth.web_token_dias'));
            if ($token->expires_at->lt($nuevo->copy()->subHour())) {
                $token->forceFill(['expires_at' => $nuevo])->save();
            }
        });
    }
}
