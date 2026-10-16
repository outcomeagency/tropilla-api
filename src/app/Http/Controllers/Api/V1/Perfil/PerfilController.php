<?php

namespace App\Http\Controllers\Api\V1\Perfil;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Perfil\Actions\GestionarPerfil;
use App\Domain\Perfil\Services\Perfiles;
use App\Domain\Perfil\Services\PerfilPublicoBuilder;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Perfil\PerfilResource;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Configuración del perfil público del establecimiento (TPA-50). */
class PerfilController extends Controller
{
    public function show(Establecimiento $establecimiento): PerfilResource
    {
        Gate::authorize('perfil.ver_preview', $establecimiento);

        return PerfilResource::make(Perfiles::asegurar($establecimiento));
    }

    public function update(Request $request, Establecimiento $establecimiento, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.editar', $establecimiento);
        $d = $request->validate([
            'row_version' => ['required', 'integer'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9-]{3,60}$/'],
            'en_directorio' => ['nullable', 'boolean'],
            'asociaciones_visibles' => ['nullable', 'boolean'],
            'ubicacion_publica' => ['nullable', 'in:provincia,departamento,localidad'],
            'idiomas' => ['sometimes', 'array', 'min:1'], 'idiomas.*' => ['in:es,en,pt'],
            'textos' => ['nullable', 'array'], 'textos.*' => ['array:titulo,descripcion,historia'],
            'textos.*.titulo' => ['nullable', 'string', 'max:120'], 'textos.*.descripcion' => ['nullable', 'string', 'max:1000'], 'textos.*.historia' => ['nullable', 'string', 'max:5000'],
            'contacto_visible' => ['nullable', 'array:telefono,email,whatsapp'], 'contacto_visible.*' => ['boolean'],
            'secciones' => ['nullable', 'array'], 'secciones.*.activa' => ['sometimes', 'boolean'], 'secciones.rodeo.modo' => ['sometimes', 'in:exacto,rango'],
            'fotos' => ['sometimes', 'array'],
        ]);

        return PerfilResource::make($gestionar->editar($establecimiento, $request->user(), $d));
    }

    public function slugDisponible(Request $request, Establecimiento $establecimiento, GestionarPerfil $gestionar): JsonResponse
    {
        Gate::authorize('perfil.editar', $establecimiento);

        return response()->json(['data' => ['disponible' => $gestionar->slugDisponible((string) $request->query('slug', ''), $establecimiento->id)]]);
    }

    public function preview(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('perfil.ver_preview', $establecimiento);

        return response()->json(['data' => PerfilPublicoBuilder::armar(Perfiles::asegurar($establecimiento), Leyenda::locale($request->query('locale')))]);
    }

    public function aceptarPreview(Request $request, Establecimiento $establecimiento, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.publicar', $establecimiento);

        return PerfilResource::make($gestionar->aceptarPreview($establecimiento, $request->user()));
    }

    public function publicar(Request $request, Establecimiento $establecimiento, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.publicar', $establecimiento);

        return PerfilResource::make($gestionar->publicar($establecimiento, $request->user()));
    }

    public function despublicar(Request $request, Establecimiento $establecimiento, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.publicar', $establecimiento);

        return PerfilResource::make($gestionar->despublicar($establecimiento, $request->user()));
    }

    public function congelar(Request $request, Establecimiento $establecimiento, string $seccion, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.editar', $establecimiento);

        return PerfilResource::make($gestionar->congelar($establecimiento, $request->user(), $seccion, true));
    }

    public function descongelar(Request $request, Establecimiento $establecimiento, string $seccion, GestionarPerfil $gestionar): PerfilResource
    {
        Gate::authorize('perfil.editar', $establecimiento);

        return PerfilResource::make($gestionar->congelar($establecimiento, $request->user(), $seccion, false));
    }
}
