<?php

namespace App\Providers;

use App\Domain\Documentos\Almacen\AlmacenObjetos;
use App\Domain\Documentos\Almacen\LocalAlmacen;
use App\Domain\Documentos\Almacen\S3Almacen;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Establecimientos\Policies\EstablecimientoPolicy;
use App\Domain\Establecimientos\Policies\ProductorPolicy;
use App\Domain\Hacienda\Proyecciones\SugeridorCategoria;
use App\Domain\Integridad\Anclaje\AnchorDriver;
use App\Domain\Integridad\Anclaje\OpenTimestampsDriver;
use App\Domain\Integridad\Anclaje\StubDriver;
use App\Domain\Notificaciones\Push\FcmPushDriver;
use App\Domain\Notificaciones\Push\LogPushDriver;
use App\Domain\Notificaciones\Push\PushDriver;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Almacén de la subida diferida (TPA-44): S3/MinIO o local (dev sin MinIO, tests).
        $this->app->bind(AlmacenObjetos::class, fn () => config('tropilla.archivos.driver') === 's3' ? new S3Almacen : new LocalAlmacen);

        // Anclaje (TPA-40): OpenTimestamps o stub.
        $this->app->bind(AnchorDriver::class, fn () => config('tropilla.anclaje.driver') === 'opentimestamps'
            ? new OpenTimestampsDriver(config('tropilla.anclaje.calendarios'), config('tropilla.anclaje.explorador'))
            : new StubDriver);

        // Push (TPA-53): FCM o log. Singleton para que el log de los tests se pueda inspeccionar.
        $this->app->singleton(PushDriver::class, fn () => config('tropilla.push.driver') === 'fcm'
            ? FcmPushDriver::desdeArchivo(config('tropilla.push.fcm_credenciales'))
            : new LogPushDriver);

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
        // sync §6.3: con el driver stub en producción la app no arranca (sería «integridad registrada» falsa).
        if ($this->app->environment('production') && config('tropilla.anclaje.driver') === 'stub') {
            throw new RuntimeException('TROPILLA_ANCLAJE_DRIVER=stub no está permitido en producción.');
        }

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
        // Fotos (TPA-54): una página del directorio pide decenas; van con cache inmutable, el límite solo frena abusos.
        RateLimiter::for('fotos', fn (Request $request) => Limit::perMinute(1200)->by('fotos|'.$request->ip()));
        RateLimiter::for('sync-push', fn (Request $request) => Limit::perMinute(60)->by('push|'.($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
        RateLimiter::for('consultas', fn (Request $request) => Limit::perHour(3)->by('consultas|'.$request->ip()));
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
