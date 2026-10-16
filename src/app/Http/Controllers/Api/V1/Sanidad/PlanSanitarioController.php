<?php

namespace App\Http\Controllers\Api\V1\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Actions\GestionarPlan;
use App\Domain\Sanidad\GeneradorTareas;
use App\Domain\Sanidad\Models\PlanItem;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Requests\V1\Sanidad\AplicarPlantillasRequest;
use App\Http\Requests\V1\Sanidad\PlanItemRequest;
use App\Http\Resources\V1\Sanidad\PlanItemResource;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Plan sanitario desde plantillas (TPA-33): sugerencia, nunca dosis; el aviso veterinario se acepta explícitamente. */
class PlanSanitarioController extends Controller
{
    public const AVISO = 'Validá este plan con tu veterinario. Los productos, dosis y fechas definitivos los decide un profesional.';

    public function show(Establecimiento $establecimiento, GestionarPlan $gestionar): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);

        return $this->plan($establecimiento, $gestionar);
    }

    public function plantillas(AplicarPlantillasRequest $request, Establecimiento $establecimiento, GestionarPlan $gestionar): JsonResponse
    {
        $gestionar->aplicarPlantillas($establecimiento, $request->user(), $request->validated('plantillas'));

        return $this->plan($establecimiento, $gestionar);
    }

    public function storeItem(PlanItemRequest $request, Establecimiento $establecimiento, GestionarPlan $gestionar): JsonResponse
    {
        $item = $gestionar->crearItem($establecimiento, $request->user(), $request->datos());

        return $this->item($establecimiento, $item)->response()->setStatusCode(201);
    }

    public function updateItem(PlanItemRequest $request, Establecimiento $establecimiento, string $item, GestionarPlan $gestionar): PlanItemResource
    {
        return $this->item($establecimiento, $gestionar->editarItem($establecimiento, $this->buscar($establecimiento, $item), $request->user(), $request->datos()));
    }

    public function archivarItem(MotivoRequest $request, Establecimiento $establecimiento, string $item, GestionarPlan $gestionar): PlanItemResource
    {
        Gate::authorize('plan.editar', $establecimiento);

        return $this->item($establecimiento, $gestionar->archivarItem($establecimiento, $this->buscar($establecimiento, $item), $request->user(), $request->validated('motivo')));
    }

    private function plan(Establecimiento $est, GestionarPlan $gestionar): JsonResponse
    {
        $plan = $gestionar->plan($est);
        $hechos = GeneradorTareas::hechos($est);
        $items = PlanItem::query()->where('establecimiento_id', $est->id)->whereNull('archivado_en')->orderBy('created_at')->orderBy('id')->get()
            ->each(fn ($i) => $i->setAttribute('aplica', GeneradorTareas::aplica($i, $est, $hechos)));
        $por = $plan->aviso_aceptado_por ? DB::table('usuarios')->where('id', $plan->aviso_aceptado_por)->first(['id', 'nombre']) : null;

        return response()->json(['data' => [
            'aviso' => __(self::AVISO),
            'aviso_aceptado_en' => $plan->aviso_aceptado_en ? Formato::fechaHora(Carbon::parse($plan->aviso_aceptado_en)) : null,
            'aviso_aceptado_por' => $por ? ['id' => $por->id, 'nombre' => $por->nombre] : null,
            'items' => PlanItemResource::collection($items)->resolve(),
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function item(Establecimiento $est, PlanItem $item): PlanItemResource
    {
        return PlanItemResource::make($item->setAttribute('aplica', GeneradorTareas::aplica($item, $est, GeneradorTareas::hechos($est))));
    }

    private function buscar(Establecimiento $est, string $id): PlanItem
    {
        return PlanItem::query()->where('establecimiento_id', $est->id)->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }
}
