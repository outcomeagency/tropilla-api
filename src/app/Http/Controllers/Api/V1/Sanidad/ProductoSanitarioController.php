<?php

namespace App\Http\Controllers\Api\V1\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Actions\GuardarProductoSanitario;
use App\Domain\Sanidad\Data\ProductosSugeridos;
use App\Domain\Sanidad\Models\ProductoSanitario;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Requests\V1\Sanidad\ProductoSanitarioRequest;
use App\Http\Resources\V1\Sanidad\ProductoSanitarioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Catálogo de productos sanitarios del establecimiento (TPA-31) + base sugerida sin dosis. */
class ProductoSanitarioController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $request->validate(['tipo' => ['sometimes', 'in:'.implode(',', ProductoSanitario::TIPOS)], 'especie' => ['sometimes', 'exists:especies,codigo']]);

        $productos = ProductoSanitario::query()->where('establecimiento_id', $establecimiento->id)
            ->when(! $request->boolean('archivados'), fn ($q) => $q->whereNull('archivado_en'))
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('tipo', $t))
            ->when($request->query('especie'), fn ($q, $e) => $q->where(fn ($w) => $w->whereRaw('? = ANY(especies)', [$e])->orWhereRaw("especies = '{}'")))
            ->orderByRaw('lower(nombre_comercial)')->get();

        return ProductoSanitarioResource::collection($productos);
    }

    public function store(ProductoSanitarioRequest $request, Establecimiento $establecimiento, GuardarProductoSanitario $guardar): JsonResponse
    {
        ['producto' => $p, 'creado' => $creado] = $guardar->crear($establecimiento, $request->user(), $request->validated());

        return ProductoSanitarioResource::make($p)->response()->setStatusCode($creado ? 201 : 200);
    }

    public function update(ProductoSanitarioRequest $request, Establecimiento $establecimiento, string $producto, GuardarProductoSanitario $guardar): ProductoSanitarioResource
    {
        return ProductoSanitarioResource::make($guardar->editar($this->producto($establecimiento, $producto), $request->user(), $request->validated()));
    }

    public function archivar(MotivoRequest $request, Establecimiento $establecimiento, string $producto, GuardarProductoSanitario $guardar): ProductoSanitarioResource
    {
        Gate::authorize('maestros.editar', $establecimiento);

        return ProductoSanitarioResource::make($guardar->archivar($this->producto($establecimiento, $producto), $request->user(), $request->validated('motivo')));
    }

    /** GET /catalogos/productos-sugeridos?especie — sin dosis ni retiro (los completa el usuario). */
    public function sugeridos(Request $request): JsonResponse
    {
        $request->validate(['especie' => ['sometimes', 'exists:especies,codigo']]);

        return response()->json(['data' => ProductosSugeridos::listar($request->query('especie'))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function producto(Establecimiento $establecimiento, string $id): ProductoSanitario
    {
        return ProductoSanitario::query()->where('establecimiento_id', $establecimiento->id)
            ->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }
}
