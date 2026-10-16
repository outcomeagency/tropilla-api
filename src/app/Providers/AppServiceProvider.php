<?php

namespace App\Providers;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Establecimientos\Policies\EstablecimientoPolicy;
use App\Domain\Establecimientos\Policies\ProductorPolicy;
use App\Domain\Hacienda\Proyecciones\SugeridorCategoria;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Memoiza reglas de categoría por request/job.
        $this->app->scoped(SugeridorCategoria::class);
    }

    /** Parámetros de ruta que son UUID: un valor mal formado da 404 (y no un error de Postgres). */
    private const PARAMETROS_UUID = [
        'establecimiento', 'productor', 'membresia', 'invitacion', 'potrero', 'lote', 'animal', 'evento',
        'revision', 'producto', 'item', 'tarea', 'documento', 'enlace', 'subida', 'importacion', 'exportacion',
        'operacion', 'oferta', 'consulta', 'contrato', 'toma', 'busco', 'organizacion', 'adhesion', 'notificacion',
    ];

    public function boot(): void
    {
        foreach (self::PARAMETROS_UUID as $parametro) {
            Route::pattern($parametro, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        // Autorización: membresía activa + ability del rol (modelo-datos §13). Un Gate por ability.
        Gate::policy(Productor::class, ProductorPolicy::class);
        foreach (array_keys(Rol::MATRIZ) as $ability) {
            Gate::define($ability, fn (User $usuario, Establecimiento $establecimiento) => app(EstablecimientoPolicy::class)->permite($usuario, $establecimiento, $ability));
        }

        // api-v1.md §5: login/registro 5/min/IP; resto 300/min/usuario.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by('auth|'.$request->ip()));
        RateLimiter::for('publico', fn (Request $request) => Limit::perMinute(120)->by('publico|'.$request->ip()));
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
