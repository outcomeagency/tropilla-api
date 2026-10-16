<?php

namespace App\Domain\Sanidad\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sanidad\GeneradorTareas;
use App\Domain\Sanidad\Models\PlanItem;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan sanitario (TPA-33): aplicar plantillas (con el aviso veterinario aceptado: quién y cuándo), ítems manuales,
 * edición con `row_version` y archivo. Después de cada cambio se regeneran las tareas (idempotente).
 * Las plantillas son **sugerencias**: nunca dosis; todo es editable.
 */
final class GestionarPlan
{
    public function __construct(
        private readonly RegistrarAuditoria $auditoria,
        private readonly GeneradorTareas $generador,
    ) {}

    public function plan(Establecimiento $est): object
    {
        return DB::table('planes_sanitarios')->where('establecimiento_id', $est->id)->first()
            ?? (object) ['id' => null, 'aviso_aceptado_en' => null, 'aviso_aceptado_por' => null];
    }

    /** @param list<string> $codigos */
    public function aplicarPlantillas(Establecimiento $est, User $usuario, array $codigos): void
    {
        DB::transaction(function () use ($est, $usuario, $codigos) {
            Cadena::bloquearDeEstablecimiento($est->id);
            $planId = $this->asegurarPlan($est);
            DB::table('planes_sanitarios')->where('id', $planId)->update(['aviso_aceptado_en' => now(), 'aviso_aceptado_por' => $usuario->id, 'updated_at' => now()]);

            $plantillas = DB::table('plantillas_sanitarias')->whereIn('codigo', $codigos)->where('activa', true)->get()->keyBy('codigo');
            $errores = [];
            foreach ($codigos as $i => $c) {
                $p = $plantillas->get($c);
                if ($p === null) {
                    $errores["plantillas.{$i}"][] = __('Plantilla desconocida o inactiva.');
                } elseif ($p->especie_codigo !== null && ! in_array($p->especie_codigo, $est->especies, true)) {
                    $errores["plantillas.{$i}"][] = __('La plantilla es de una especie que el establecimiento no declara.');
                }
            }
            if ($errores !== []) {
                throw ApiException::validacion(__('Revisá las plantillas elegidas.'), $errores);
            }

            foreach ($codigos as $c) {
                $p = $plantillas[$c];
                $ya = PlanItem::query()->where('establecimiento_id', $est->id)->where('plantilla_codigo', $c)->whereNull('archivado_en')->exists();
                if ($ya) {
                    continue; // reaplicar no duplica
                }
                $item = PlanItem::query()->create([
                    'plan_id' => $planId, 'establecimiento_id' => $est->id, 'plantilla_codigo' => $c, 'plantilla_version' => (int) $p->version,
                    'nombre' => json_decode($p->nombres, true)['es'] ?? $c, 'especie_codigo' => $p->especie_codigo, 'tipo_evento' => $p->tipo_evento,
                    'enfermedad' => $p->enfermedad, 'alcance' => json_decode($p->alcance, true) ?: [], 'disparador' => json_decode($p->disparador, true),
                    'repeticion' => $p->repeticion ? json_decode($p->repeticion, true) : null, 'condicion' => $p->condicion ? json_decode($p->condicion, true) : null,
                    'nota' => $p->nota,
                ]);
                $this->auditoria->handle($est->id, $usuario->id, 'plan_item', $item->id, 'crear', null, ['plantilla' => $c]);
            }
            $this->auditoria->handle($est->id, $usuario->id, 'plan_sanitario', $planId, 'aviso_aceptado', null, ['plantillas' => $codigos]);
            $this->generador->generar($est);
        });
    }

    /** @param array<string, mixed> $datos */
    public function crearItem(Establecimiento $est, User $usuario, array $datos): PlanItem
    {
        return DB::transaction(function () use ($est, $usuario, $datos) {
            Cadena::bloquearDeEstablecimiento($est->id);
            if (! empty($datos['id']) && ($existente = PlanItem::query()->find($datos['id']))) {
                if ($existente->establecimiento_id !== $est->id) {
                    throw ApiException::validacion(__('El id ya está en uso.'), ['id' => [__('El id ya está en uso.')]]);
                }

                return $existente;
            }
            $item = new PlanItem([...array_intersect_key($datos, array_flip(PlanItem::CAMPOS)), 'plan_id' => $this->asegurarPlan($est), 'establecimiento_id' => $est->id]);
            if (! empty($datos['id'])) {
                $item->id = $datos['id'];
            }
            $item->save();
            $this->auditoria->handle($est->id, $usuario->id, 'plan_item', $item->id, 'crear', null, $item->only(PlanItem::CAMPOS));
            $this->generador->generar($est);

            return $item->refresh();
        });
    }

    /** @param array<string, mixed> $datos */
    public function editarItem(Establecimiento $est, PlanItem $item, User $usuario, array $datos): PlanItem
    {
        return DB::transaction(function () use ($est, $item, $usuario, $datos) {
            Cadena::bloquearDeEstablecimiento($est->id);
            /** @var PlanItem $i */
            $i = PlanItem::query()->lockForUpdate()->findOrFail($item->id);
            if ((int) $datos['row_version'] !== $i->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $i->row_version]);
            }
            if ($i->archivado_en !== null) {
                throw ApiException::reglaBloqueante(__('El ítem está archivado.'));
            }
            $antes = $i->only(PlanItem::CAMPOS);
            $i->fill(array_intersect_key($datos, array_flip(PlanItem::CAMPOS)));
            if (! $i->isDirty()) {
                return $i;
            }
            $i->editado = $i->plantilla_codigo !== null;
            $i->row_version++;
            $i->save();
            $this->auditoria->handle($est->id, $usuario->id, 'plan_item', $i->id, 'editar', $antes, $i->only(PlanItem::CAMPOS));
            $this->generador->generar($est);

            return $i->refresh();
        });
    }

    public function archivarItem(Establecimiento $est, PlanItem $item, User $usuario, string $motivo): PlanItem
    {
        return DB::transaction(function () use ($est, $item, $usuario, $motivo) {
            Cadena::bloquearDeEstablecimiento($est->id);
            /** @var PlanItem $i */
            $i = PlanItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($i->archivado_en === null) {
                $i->forceFill(['archivado_en' => now(), 'archivado_por' => $usuario->id, 'row_version' => $i->row_version + 1])->save();
                $this->auditoria->handle($est->id, $usuario->id, 'plan_item', $i->id, 'archivar', null, null, $motivo);
                $this->generador->generar($est); // omite sus tareas pendientes futuras
            }

            return $i;
        });
    }

    private function asegurarPlan(Establecimiento $est): string
    {
        $id = DB::table('planes_sanitarios')->where('establecimiento_id', $est->id)->value('id');
        if ($id === null) {
            $id = (string) Str::uuid7();
            DB::table('planes_sanitarios')->insert(['id' => $id, 'establecimiento_id' => $est->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }
}
