<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AsignarRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formato único de error de la API (api-v1.md §3):
 * {message, code, errors?, advertencias?, request_id} + header X-Request-Id.
 */
$error = function (Request $request, int $status, string $code, string $message, array $errors = [], array $advertencias = [], array $headers = []): JsonResponse {
    $body = ['message' => $message, 'code' => $code];
    if ($errors !== []) {
        $body['errors'] = $errors;
    }
    if ($advertencias !== []) {
        $body['advertencias'] = $advertencias;
    }
    $body['request_id'] = (string) $request->attributes->get('request_id', '');

    return response()->json($body, $status, $headers + ['X-Request-Id' => $body['request_id']]);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AsignarRequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $esApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (Throwable $e, Request $request) use ($error, $esApi) {
            if (! $esApi($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => $error($request, $e->status, $e->codigo, $e->getMessage(), $e->errors, $e->advertencias),
                $e instanceof ValidationException => $error($request, 422, 'validacion', __('Los datos enviados no son válidos.'), $e->errors()),
                $e instanceof AuthenticationException => $error($request, 401, 'no_autenticado', __('No autenticado.')),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => $error($request, 403, 'sin_permiso', __('No tenés permiso para esta acción.')),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $error($request, 404, 'no_encontrado', __('No encontrado.')),
                $e instanceof ThrottleRequestsException => $error($request, 429, 'demasiados_intentos', __('Demasiados intentos. Esperá un momento.'), headers: $e->getHeaders()),
                $e instanceof HttpExceptionInterface => $error($request, $e->getStatusCode(), match ($e->getStatusCode()) {
                    400 => 'request_invalido',
                    405 => 'metodo_no_permitido',
                    409 => 'conflicto',
                    410 => 'enlace_vencido',
                    503 => 'mantenimiento',
                    default => 'error_http',
                }, $e->getMessage() ?: __('Error.'), headers: $e->getHeaders()),
                default => config('app.debug') ? null : $error($request, 500, 'error_interno', __('Error interno. Ya quedó registrado.')),
            };
        });
    })->create();
