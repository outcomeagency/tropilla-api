<?php

namespace App\Domain\Busco;

use App\Casts\PgTextArray;
use App\Domain\Notificaciones\Notificador;
use Illuminate\Support\Facades\DB;

/**
 * «Demandas que calzan con tu hacienda» (TPA-58, §9.7). Contra establecimientos con perfil publicado: especie y al menos una
 * categoría buscada con cabezas (existencias) son obligatorias; zona por provincia/departamento declarados (o «cualquiera»).
 * Puntaje 0–100: categoría con cabezas suficientes 50 (parciales 30) + zona 25 + raza 25 (si no pide raza, 25). Avisa una vez a
 * dueño/admin de cada establecimiento nuevo que calza. Se recalcula al publicar/editar y cada día (existencias cambian).
 */
final class Coincidencias
{
    public function __construct(private readonly Notificador $notificador) {}

    public function calcular(object $b): int
    {
        if ($b->estado !== 'abierta') {
            DB::table('busco_coincidencias')->where('busco_id', $b->id)->delete();

            return 0;
        }
        $cats = PgTextArray::parse($b->categorias);
        $razas = PgTextArray::parse($b->razas);
        $zona = json_decode($b->zona, true) ?: [];
        $ests = DB::table('perfiles_publicos as p')->join('establecimientos as e', 'e.id', '=', 'p.establecimiento_id')->where('p.publicado', true)
            ->whereNull('e.archivado_en')
            ->whereNotIn('e.id', DB::table('membresias')->where('usuario_id', $b->comprador_id)->where('estado', 'activa')->select('establecimiento_id'))
            ->get(['e.id', 'e.provincia_codigo', 'e.departamento_codigo']);
        $vigentes = [];
        foreach ($ests as $e) {
            $cabezas = (int) DB::table('existencias')->where('establecimiento_id', $e->id)->where('especie_codigo', $b->especie_codigo)
                ->when($cats !== [], fn ($q) => $q->whereIn('categoria_codigo', $cats))->sum('cabezas');
            if ($cabezas === 0) {
                continue;
            }
            $enZona = ! empty($zona['cualquiera']) || (empty($zona['provincias']) && empty($zona['departamentos']))
                || in_array($e->provincia_codigo, (array) ($zona['provincias'] ?? []), true) || in_array($e->departamento_codigo, (array) ($zona['departamentos'] ?? []), true);
            if (! $enZona) {
                continue;
            }
            $razaOk = $razas === [] || DB::table('animales_estado')->where('establecimiento_id', $e->id)->where('estado', 'activo')->whereIn('raza_codigo', $razas)->exists();
            $puntaje = ($cabezas >= $b->cantidad ? 50 : 30) + 25 + ($razaOk ? 25 : 0);
            $motivo = ['cabezas' => $cabezas, 'cantidad_pedida' => (int) $b->cantidad, 'zona' => true, 'raza' => $razaOk];
            $vigentes[] = $e->id;
            $previa = DB::table('busco_coincidencias')->where('busco_id', $b->id)->where('establecimiento_id', $e->id)->first();
            DB::table('busco_coincidencias')->updateOrInsert(['busco_id' => $b->id, 'establecimiento_id' => $e->id], ['puntaje' => $puntaje, 'motivo' => json_encode($motivo)]);
            if ($previa === null) {
                $this->notificador->aRoles($e->id, ['dueno', 'admin'], 'busco_coincide', 'Un comprador busca hacienda que tenés',
                    "Busca {$b->cantidad} cabezas ({$b->especie_codigo}). Respondé desde Comercialización.", ['busco_id' => $b->id, 'ruta' => '/comercial/demandas'], "busco:{$b->id}:{$e->id}");
                DB::table('busco_coincidencias')->where('busco_id', $b->id)->where('establecimiento_id', $e->id)->update(['notificado_en' => now()]);
            }
        }
        DB::table('busco_coincidencias')->where('busco_id', $b->id)->whereNotIn('establecimiento_id', $vigentes)->delete();

        return count($vigentes);
    }
}
