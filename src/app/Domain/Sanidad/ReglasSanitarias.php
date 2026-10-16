<?php

namespace App\Domain\Sanidad;

use App\Domain\Hacienda\Eventos\ContextoEvento;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reglas de los eventos `sanidad.*` (TPA-32..34) que llama `RegistrarEvento` (única puerta, web y push):
 * bloqueantes en el paso 8 y advertencias en el 10. Todo lo que depende de especie sale de datos (catálogos/productos).
 */
final class ReglasSanitarias
{
    /** Tipos que aplican un producto (copian el retiro al payload). */
    public const APLICACIONES = ['sanidad.vacunacion', 'sanidad.desparasitacion', 'sanidad.tratamiento'];

    public function validar(ContextoEvento $ctx): void
    {
        $s = $ctx->sobre;
        $p = $s->payload ?? new stdClass;

        if (isset($p->producto_id) && $this->producto($ctx, $p->producto_id) === null) {
            throw new EventoRechazado('dependencia_faltante', 'El producto todavía no está en el servidor.', ['payload.producto_id' => [$p->producto_id]]);
        }
        if ($s->tipo === 'sanidad.vacunacion' && ! $this->enCatalogo($ctx, 'enfermedades', $p->enfermedad)) {
            throw new EventoRechazado('esquema_invalido', 'Enfermedad desconocida.', ['payload.enfermedad' => ['No está en el catálogo.']]);
        }

        // Potrero: el servidor resuelve los animales individuales presentes al registrar (propio o el de su lote),
        // para que el retiro llegue a cada ficha. Queda fijo en `evento_animales` (append-only).
        if (($s->sujetos->tipo ?? null) === 'potrero' && in_array($s->tipo, self::APLICACIONES, true)) {
            $ctx->animalesResueltos = DB::table('animales_estado as e')
                ->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'e.lote_id')
                ->where('e.establecimiento_id', $ctx->est->id)->where('e.estado', 'activo')
                ->where(DB::raw('COALESCE(e.potrero_id, le.potrero_id)'), $s->sujetos->potrero_id)
                ->orderBy('e.animal_id')->pluck('e.animal_id')->all();
        }
    }

    /** @return list<array{codigo: string, mensaje: string, interna: bool, detalle?: array<string, mixed>}> */
    public function observaciones(ContextoEvento $ctx): array
    {
        $s = $ctx->sobre;
        $p = $s->payload ?? new stdClass;
        $obs = [];
        $fecha = $ctx->ocurridoEn()->toDateString();

        if (isset($p->envase_vence) && $p->envase_vence < $fecha) {
            $obs[] = ['codigo' => 'envase_vencido', 'mensaje' => "El envase venció el {$p->envase_vence}.", 'interna' => false, 'detalle' => ['envase_vence' => $p->envase_vence]];
        }
        if (! in_array($s->tipo, self::APLICACIONES, true) || ! isset($p->producto_id)) {
            return $obs;
        }
        $prod = $this->producto($ctx, $p->producto_id);
        foreach (['carne', 'leche'] as $t) {
            $delProducto = $prod->{"retiro_{$t}_dias"};
            $declarado = $p->{"retiro_{$t}_dias"} ?? null;
            if ($delProducto !== null && ($declarado === null || $declarado < $delProducto)) {
                $obs[] = ['codigo' => 'retiro_menor_al_producto', 'mensaje' => "El retiro de {$t} cargado es menor al del producto ({$delProducto} días).", 'interna' => false,
                    'detalle' => ['tipo' => $t, 'producto_dias' => (int) $delProducto, 'declarado_dias' => $declarado]];
            }
        }
        $especiesProducto = array_filter(explode(',', trim((string) $prod->especies, '{}')));
        if ($especiesProducto !== []) {
            $especies = array_unique([...array_map(fn ($a) => $a->especie_codigo, $ctx->sujetosExistentes), ...($ctx->lote ? [$ctx->lote->especie_codigo] : [])]);
            $fuera = array_values(array_diff($especies, $especiesProducto));
            if ($fuera !== []) {
                $obs[] = ['codigo' => 'producto_otra_especie', 'mensaje' => 'El producto no está declarado para '.implode(', ', $fuera).'.', 'interna' => false, 'detalle' => ['especies' => $fuera]];
            }
        }

        return $obs;
    }

    private function producto(ContextoEvento $ctx, string $id): ?object
    {
        return DB::table('productos_sanitarios')->where('id', $id)->where('establecimiento_id', $ctx->est->id)->first();
    }

    private function enCatalogo(ContextoEvento $ctx, string $lista, ?string $codigo): bool
    {
        return $codigo !== null && DB::table('catalogo_items')->where('lista', $lista)->where('codigo', $codigo)->where('activo', true)
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $ctx->est->id))->exists();
    }
}
