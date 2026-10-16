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

    /** Eventos que pueden generar tareas relativas del plan (nacimiento + N días, refuerzo tras primera dosis). */
    public const DISPARADORES_PLAN = ['hacienda.nacimiento', 'reproduccion.parto', 'hacienda.alta', 'sanidad.vacunacion'];

    public function validar(ContextoEvento $ctx): void
    {
        $s = $ctx->sobre;
        $p = $s->payload ?? new stdClass;

        if (isset($p->producto_id) && $this->producto($ctx, $p->producto_id) === null) {
            throw new EventoRechazado('dependencia_faltante', 'El producto todavía no está en el servidor.', ['payload.producto_id' => [$p->producto_id]]);
        }
        if (isset($p->tarea_id) && ! DB::table('tareas')->where('id', $p->tarea_id)->where('establecimiento_id', $ctx->est->id)->exists()) {
            throw new EventoRechazado('dependencia_faltante', 'La tarea todavía no está en el servidor.', ['payload.tarea_id' => [$p->tarea_id]]);
        }
        if ($s->tipo === 'sanidad.vacunacion' && ! $this->enCatalogo($ctx, 'enfermedades', $p->enfermedad)) {
            throw new EventoRechazado('esquema_invalido', 'Enfermedad desconocida.', ['payload.enfermedad' => ['No está en el catálogo.']]);
        }

        if ($s->tipo === 'sanidad.prueba_diagnostica') {
            $this->prueba($ctx, $p);
        }
        foreach ((array) ($p->documento_ids ?? []) as $docId) {
            if (! DB::table('documentos')->where('id', $docId)->where('establecimiento_id', $ctx->est->id)->exists()) {
                throw new EventoRechazado('dependencia_faltante', 'El documento todavía no está en el servidor.', ['payload.documento_ids' => [$docId]]);
            }
        }

        // Potrero: el servidor resuelve los animales individuales presentes al registrar (propio o el de su lote),
        // para que el retiro llegue a cada ficha. Queda fijo en `evento_animales` (append-only).
        if (($s->sujetos->tipo ?? null) === 'potrero' && in_array($s->tipo, self::APLICACIONES, true)) {
            $ctx->animalesResueltos = DB::table('animales_estado as e')
                ->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'e.lote_id')
                ->where('e.establecimiento_id', $ctx->est->id)->where('e.estado', 'activo')
                ->where(DB::raw('COALESCE(e.potrero_id, le.potrero_id)'), $s->sujetos->potrero_id)
                ->orderBy('e.animal_id')->pluck('e.animal_id')->all();
            // Y los lotes anónimos que están en ese potrero (bugfix TPA-35): su retiro se deriva de `evento_lotes`.
            $ctx->lotesResueltos = DB::table('lotes as l')->join('lotes_estado as le', 'le.lote_id', '=', 'l.id')
                ->where('l.establecimiento_id', $ctx->est->id)->where('l.anonimo', true)->whereNull('l.archivado_en')
                ->where('le.potrero_id', $s->sujetos->potrero_id)->where('le.cabezas_anonimas', '>', 0)
                ->orderBy('l.id')->pluck('l.id')->all();
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
        if ($s->tipo === 'sanidad.prueba_diagnostica') {
            return [...$obs, ...$this->observacionesPrueba($ctx, $p)];
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

    /**
     * Prueba diagnóstica (TPA-34): tipo del catálogo para la especie; con lecturas, un solo animal; `calculado` exige lecturas y
     * coincidir con los umbrales vigentes (web → 422; app → aceptado con revisión: los umbrales pudieron cambiar offline).
     */
    private function prueba(ContextoEvento $ctx, stdClass $p): void
    {
        $tipo = DB::table('tipos_prueba')->where('codigo', $p->prueba)->first();
        if ($tipo === null) {
            throw new EventoRechazado('esquema_invalido', 'Prueba desconocida.', ['payload.prueba' => ['No está en el catálogo.']]);
        }
        $especies = array_filter(explode(',', trim((string) $tipo->especies, '{}')));
        foreach ($ctx->sujetosExistentes as $a) {
            if ($especies !== [] && ! in_array($a->especie_codigo, $especies, true)) {
                throw new EventoRechazado('regla_bloqueante', 'La prueba no corresponde a la especie.', ['payload.prueba' => [$a->especie_codigo]]);
            }
        }
        $conLecturas = isset($p->lectura_0h_mm) || isset($p->lectura_72h_mm);
        if ($conLecturas && count($ctx->sujetosExistentes) !== 1) {
            throw new EventoRechazado('regla_bloqueante', 'Las lecturas en mm son de un solo animal.', ['sujetos.animal_ids' => ['Un animal por lectura.']]);
        }
        if ($p->resultado_origen === 'calculado') {
            $calc = $this->calcular($ctx, $p);
            if ($calc === null) {
                throw new EventoRechazado('esquema_invalido', 'Un resultado calculado lleva las lecturas de 0 h y 72 h de una prueba con umbrales.', ['payload.resultado_origen' => ['Sin lecturas o sin umbrales.']]);
            }
            if ($ctx->canal === 'web' && $calc['resultado'] !== $p->resultado) {
                throw new EventoRechazado('regla_bloqueante', "Con los umbrales vigentes (Δ {$calc['diferencia_mm']} mm) el resultado es «{$calc['resultado']}».", ['payload.resultado' => [$calc['resultado']]]);
            }
            $ctx->extra['prueba_calculada'] = $calc;
        }
    }

    /** @return list<array<string, mixed>> */
    private function observacionesPrueba(ContextoEvento $ctx, stdClass $p): array
    {
        $obs = [];
        if (isset($p->lectura_0h_mm, $p->lectura_72h_mm) && bccomp($p->lectura_72h_mm, $p->lectura_0h_mm, 1) < 0) {
            $obs[] = ['codigo' => 'lectura_72h_menor', 'mensaje' => 'La lectura a las 72 h es menor que la inicial: revisá la carga.', 'interna' => false];
        }
        $calc = $ctx->extra['prueba_calculada'] ?? $this->calcular($ctx, $p);
        if ($calc !== null && $calc['resultado'] !== $p->resultado) {
            $calculado = $p->resultado_origen === 'calculado';
            $obs[] = ['codigo' => $calculado ? 'resultado_prueba_discrepante' : 'resultado_distinto_al_calculado',
                'mensaje' => "Con los umbrales vigentes (Δ {$calc['diferencia_mm']} mm) el resultado es «{$calc['resultado']}».",
                'interna' => $calculado, 'revision' => $calculado, 'detalle' => $calc + ['declarado' => $p->resultado]];
        }
        if ($p->resultado === 'positivo') {
            $obs[] = ['codigo' => 'marca_reactor', 'mensaje' => 'Resultado positivo: el animal queda marcado como reactor (dato privado).', 'interna' => false];
        }

        return $obs;
    }

    /** @return array<string, mixed>|null */
    private function calcular(ContextoEvento $ctx, stdClass $p): ?array
    {
        $a = array_values($ctx->sujetosExistentes)[0] ?? null;
        if ($a === null || count($ctx->sujetosExistentes) !== 1) {
            return null;
        }

        return ResultadoPrueba::calcular($p->prueba, $a->especie_codigo, $a->raza_codigo, $ctx->est->id, $p->lectura_0h_mm ?? null, $p->lectura_72h_mm ?? null);
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
