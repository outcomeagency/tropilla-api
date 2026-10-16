<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Sync\Actions\ProcesarPush;
use App\Domain\Sync\Services\Pull;
use App\Domain\Sync\Services\Snapshot;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/** Sincronización offline (TPA-43): push idempotente por UUID, pull por cursor y snapshot. */
class SyncController extends Controller
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function push(Request $request, ProcesarPush $push): JsonResponse
    {
        $raw = $request->getContent();
        if (str_contains(strtolower((string) $request->header('Content-Encoding')), 'gzip')) {
            $raw = @gzdecode($raw, self::MAX_BYTES + 1);
            if ($raw === false) {
                throw new ApiException(400, 'request_invalido', __('Cuerpo gzip inválido.'));
            }
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ApiException(413, 'request_demasiado_grande', __('Máximo 2 MB por envío.'));
        }
        $in = $this->cuerpo($raw);
        $errores = [];
        foreach (['establecimiento_id', 'dispositivo_id'] as $c) {
            if (! isset($in->{$c}) || ! is_string($in->{$c}) || ! preg_match('/^[0-9a-f-]{36}$/', $in->{$c})) {
                $errores[$c][] = 'UUID obligatorio.';
            }
        }
        if (! isset($in->items) || ! is_array($in->items) || count($in->items) > 200) {
            $errores['items'][] = 'Entre 0 y 200 ítems.';
        }
        if ($errores !== []) {
            throw ApiException::validacion(__('El envío no es válido.'), $errores);
        }

        $est = Establecimiento::query()->find($in->establecimiento_id) ?? throw ApiException::noEncontrado();
        $usuario = $request->user();
        if (! DB::table('dispositivos')->where('id', $in->dispositivo_id)->where('usuario_id', $usuario->id)->whereNull('revocado_en')->exists()) {
            throw ApiException::sinPermiso(__('El dispositivo no pertenece al usuario o fue revocado.'));
        }
        $acceso = $this->acceso($usuario, $est);

        return response()->json([
            'resultados' => $push->handle($est, $usuario, $in, $acceso),
            'servidor_en' => Formato::fechaHora(now()),
        ]);
    }

    public function pull(Request $request, Pull $pull): JsonResponse
    {
        $est = $this->establecimiento($request);
        Gate::authorize('sync.pull', $est);
        $rol = Membresia::query()->activas()->where('establecimiento_id', $est->id)->where('usuario_id', $request->user()->id)->value('rol');
        $limite = max(1, min(500, (int) ($request->query('limite') ?: 500)));

        return response()->json($pull->handle($est, $rol, $request, $request->query('cursor'), $limite));
    }

    public function snapshot(Request $request, Snapshot $snapshot): JsonResponse
    {
        $est = $this->establecimiento($request);
        Gate::authorize('sync.snapshot', $est);

        return response()->json($snapshot->handle($est, $request), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rol con el que se procesa el push. Revocado hace ≤ 72 h → se aceptan ítems creados antes de la revocación,
     * con observación `carga_post_revocacion` (§7.8); después → `establecimiento_cerrado`.
     *
     * @return array{rol: Rol, post_revocacion: bool, revocada_en: ?Carbon}
     */
    private function acceso($usuario, Establecimiento $est): array
    {
        $activa = Membresia::query()->activas()->where('establecimiento_id', $est->id)->where('usuario_id', $usuario->id)->first();
        if ($activa !== null) {
            Gate::forUser($usuario)->authorize('sync.push', $est);

            return ['rol' => $activa->rol, 'post_revocacion' => false, 'revocada_en' => null];
        }
        $revocada = Membresia::query()->where('establecimiento_id', $est->id)->where('usuario_id', $usuario->id)
            ->where('estado', 'revocada')->orderByDesc('revocada_en')->first();
        if ($revocada === null) {
            throw ApiException::noEncontrado();
        }
        if ($revocada->revocada_en->lt(now()->subHours(72))) {
            throw new ApiException(403, 'establecimiento_cerrado', __('El acceso a este establecimiento fue revocado.'));
        }

        return ['rol' => $revocada->rol, 'post_revocacion' => true, 'revocada_en' => $revocada->revocada_en];
    }

    private function establecimiento(Request $request): Establecimiento
    {
        $id = (string) $request->query('establecimiento_id', '');
        if (! preg_match('/^[0-9a-f-]{36}$/', $id)) {
            throw ApiException::validacion(__('Falta establecimiento_id.'), ['establecimiento_id' => ['Obligatorio.']]);
        }

        return Establecimiento::query()->find($id) ?? throw ApiException::noEncontrado();
    }

    private function cuerpo(string $raw): stdClass
    {
        try {
            $in = CanonicalJson::decode($raw ?: '{}');
        } catch (CanonicalizacionInvalida) {
            throw new ApiException(400, 'request_invalido', __('JSON mal formado.'));
        }
        if (! $in instanceof stdClass) {
            throw new ApiException(400, 'request_invalido', __('Se espera un objeto JSON.'));
        }

        return $in;
    }
}
