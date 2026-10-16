<?php

namespace App\Http\Controllers\Api\V1\Reproduccion;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReproductorExternoController extends Controller
{
    public function index(Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);

        return response()->json(['data' => DB::table('reproductores_externos')->where('establecimiento_id', $establecimiento->id)->orderBy('nombre')->get()]);
    }

    public function store(Request $request, Establecimiento $establecimiento, RegistrarCambio $cambio): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $datos = $this->validar($request, $establecimiento);
        $id = (string) Str::uuid();

        return DB::transaction(function () use ($datos, $establecimiento, $cambio, $id, $request) {
            DB::table('reproductores_externos')->insert(['id' => $id, 'establecimiento_id' => $establecimiento->id, ...$datos, 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reproductor_externo_versiones')->insert(['reproductor_id' => $id, 'establecimiento_id' => $establecimiento->id, 'version' => 1, 'accion' => 'alta', 'datos' => json_encode($datos), 'autor_id' => $request->user()->id, 'created_at' => now()]);
            $cambio->handle($establecimiento->id, 'reproductor_externo', $id);

            return response()->json(['data' => DB::table('reproductores_externos')->where('id', $id)->first()], 201);
        });
    }

    public function update(Request $request, Establecimiento $establecimiento, string $reproductor, RegistrarCambio $cambio): JsonResponse
    {
        Gate::authorize('hacienda.corregir', $establecimiento);
        $actual = DB::table('reproductores_externos')->where('establecimiento_id', $establecimiento->id)->where('id', $reproductor)->first() ?? abort(404);
        if ((int) $request->input('row_version') !== (int) $actual->row_version) {
            abort(409, 'Versión desactualizada.');
        }
        $datos = $this->validar($request, $establecimiento);

        return DB::transaction(function () use ($actual, $datos, $establecimiento, $cambio, $request) {
            $version = $actual->row_version + 1;
            DB::table('reproductores_externos')->where('id', $actual->id)->update([...$datos, 'row_version' => $version, 'updated_at' => now()]);
            DB::table('reproductor_externo_versiones')->insert(['reproductor_id' => $actual->id, 'establecimiento_id' => $establecimiento->id, 'version' => $version, 'accion' => 'correccion', 'datos' => json_encode($datos), 'autor_id' => $request->user()->id, 'created_at' => now()]);
            $cambio->handle($establecimiento->id, 'reproductor_externo', $actual->id);

            return response()->json(['data' => DB::table('reproductores_externos')->where('id', $actual->id)->first()]);
        });
    }

    private function validar(Request $request, Establecimiento $establecimiento): array
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:255'], 'rp' => ['nullable', 'string', 'max:100'], 'registro_genealogico' => ['nullable', 'string', 'max:120'], 'raza' => ['nullable', 'string', 'max:120'], 'especie_codigo' => ['required', 'string', 'max:32'], 'centro' => ['nullable', 'string', 'max:255'], 'madre_id' => ['nullable', 'uuid'], 'padre_id' => ['nullable', 'uuid'], 'madre_externa_id' => ['nullable', 'uuid'], 'padre_externa_id' => ['nullable', 'uuid']]);
        foreach (['madre_id', 'padre_id'] as $k) {
            if (! empty($d[$k]) && ! DB::table('animales')->where('establecimiento_id', $establecimiento->id)->where('id', $d[$k])->exists()) {
                throw ValidationException::withMessages([$k => ['Animal interno inexistente.']]);
            }
        }
        foreach (['madre_externa_id', 'padre_externa_id'] as $k) {
            if (! empty($d[$k]) && ! DB::table('reproductores_externos')->where('establecimiento_id', $establecimiento->id)->where('id', $d[$k])->exists()) {
                throw ValidationException::withMessages([$k => ['Reproductor externo inexistente.']]);
            }
        }
        if (($d['madre_externa_id'] ?? null) || ($d['padre_externa_id'] ?? null)) {
            throw ValidationException::withMessages(['genealogia' => ['No se permiten ciclos directos.']]);
        }

        return $d;
    }
}
