<?php

namespace App\Http\Controllers\Api\V1\Archivos;

use App\Domain\Documentos\AccesoArchivos;
use App\Domain\Documentos\Actions\GestionarSubida;
use App\Domain\Documentos\Almacen\AlmacenObjetos;
use App\Domain\Documentos\Almacen\LocalAlmacen;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Rules\Reglas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Subida diferida de fotos y archivos (TPA-44). */
class ArchivoController extends Controller
{
    public function iniciar(Request $request, GestionarSubida $subidas): JsonResponse
    {
        $d = $request->validate([
            'sha256' => ['required', Reglas::HASH], 'bytes' => ['required', 'integer', 'min:1', 'max:'.config('tropilla.archivos.max_bytes')],
            'mime' => ['required', 'string', 'max:100'], 'establecimiento_id' => ['required', Reglas::UUID],
        ]);
        $est = Establecimiento::query()->find($d['establecimiento_id']) ?? throw ApiException::noEncontrado();
        Gate::authorize('establecimiento.ver', $est);
        $g = Gate::forUser($request->user());
        if (! $g->allows('documentos.crear', $est) && ! $g->allows('sync.push', $est) && ! $g->allows('importaciones.gestionar', $est)) {
            throw ApiException::sinPermiso();
        }
        $tokenId = $request->user()->currentAccessToken()?->getKey();
        $dispositivo = $tokenId ? DB::table('dispositivos')->where('token_id', $tokenId)->value('id') : null;
        $r = $subidas->iniciar($request->user(), $d, $dispositivo);

        return response()->json(['data' => $r], $r['estado'] === 'completo' ? 200 : 201);
    }

    public function completar(Request $request, string $subida, GestionarSubida $subidas): JsonResponse
    {
        $d = $request->validate(['partes' => ['sometimes', 'array', 'max:10000'], 'partes.*.numero' => ['required', 'integer', 'min:1'], 'partes.*.etag' => ['required', 'string', 'max:200']]);
        $s = DB::table('subidas')->where('id', $subida)->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado();
        $a = $subidas->completar($s, array_map(fn ($p) => ['numero' => (int) $p['numero'], 'etag' => (string) $p['etag']], $d['partes'] ?? []));

        return response()->json(['data' => $this->archivo($a, false)]);
    }

    /** PUT de una parte cuando el almacén es local (dev/tests): la URL firmada es la credencial. */
    public function parte(Request $request, string $subida, int $numero): Response
    {
        $s = DB::table('subidas')->where('id', $subida)->where('estado', 'iniciada')->first() ?? throw ApiException::noEncontrado();
        if ($numero < 1 || $numero > (int) $s->partes) {
            throw ApiException::noEncontrado();
        }
        $etag = LocalAlmacen::guardarParte($s->id, $numero, $request->getContent());

        return response()->noContent(200)->withHeaders(['ETag' => '"'.$etag.'"']);
    }

    public function descargar(Request $request, string $sha256, AlmacenObjetos $almacen): RedirectResponse
    {
        $a = $this->visible($request, $sha256);
        if ($a->estado !== 'completo') {
            throw new ApiException(409, 'archivo_pendiente', __('El archivo todavía no terminó de subir.'));
        }
        $url = $almacen->urlDescarga($a->s3_clave, now()->addMinutes(5)) ?? throw new ApiException(503, 'no_disponible', __('Descarga no disponible en este entorno.'));

        return redirect()->away($url, 302);
    }

    public function estado(Request $request, string $sha256): JsonResponse
    {
        return response()->json(['data' => $this->archivo($this->visible($request, $sha256), true)]);
    }

    private function visible(Request $request, string $sha256): object
    {
        $sha = strtolower($sha256);
        $a = preg_match('/^[0-9a-f]{64}$/', $sha) ? DB::table('archivos')->where('sha256', $sha)->first() : null;
        if ($a === null || ! AccesoArchivos::puedeVer($request->user(), $sha)) {
            throw ApiException::noEncontrado();
        }

        return $a;
    }

    /** @return array<string, mixed> Archivo */
    private function archivo(object $a, bool $conUrl): array
    {
        return ['sha256' => $a->sha256, 'bytes' => $a->bytes === null ? null : (int) $a->bytes, 'mime' => $a->mime, 'estado' => $a->estado,
            'url' => $conUrl && $a->estado === 'completo' ? app(AlmacenObjetos::class)->urlDescarga($a->s3_clave, now()->addMinutes(5)) : null];
    }
}
