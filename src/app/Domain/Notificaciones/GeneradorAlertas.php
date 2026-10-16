<?php

namespace App\Domain\Notificaciones;

use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Reproduccion\TableroReproductivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alertas diarias (TPA-53, functional/04 §11) por establecimiento y rol, deduplicadas por clave (no se repiten):
 * tarea sanitaria próxima (≤ 3 días) / vencida y refuerzo de vacuna (dueño, encargado, asesor) · documento a 30/7/0 días o vencido
 * (dueño, admin) · parto probable próximo (`parto_proximo_aviso_dias`) / vencido sin registrar (encargado, asesor) · recategorizaciones
 * sugeridas (encargado, resumen del día) · entrega de contrato a 15 días (dueño, admin; cuando existan contratos).
 * Las que dependen de un hecho puntual (consulta nueva, toma, Busco) se emiten en el momento desde su Action.
 */
final class GeneradorAlertas
{
    private const SANIDAD = ['dueno', 'encargado', 'asesor'];

    private const GESTION = ['dueno', 'admin'];

    private const CAMPO = ['encargado', 'asesor'];

    public function __construct(private readonly Notificador $notificador, private readonly TableroReproductivo $repro) {}

    public function generar(Establecimiento $est): int
    {
        $hoy = Carbon::parse(Carbon::now($est->zona_horaria)->toDateString());
        $h = $hoy->toDateString();
        $n = 0;

        foreach (DB::table('tareas')->where('establecimiento_id', $est->id)->where('estado', 'pendiente')->where('vence_en', '<=', $hoy->copy()->addDays(3)->toDateString())->get() as $t) {
            $vencida = $t->vence_en < $h;
            $refuerzo = str_starts_with((string) $t->clave_ocurrencia, 'refuerzo:');
            $tipo = $refuerzo ? 'refuerzo' : 'tarea_sanitaria';
            $n += $this->notificador->aRoles($est->id, self::SANIDAD, $tipo, $vencida ? "Vencida: {$t->titulo}" : $t->titulo,
                $vencida ? "Venció el {$t->vence_en}. Registrala o omitila con motivo." : "Vence el {$t->vence_en}.",
                ['tarea_id' => $t->id, 'ruta' => '/sanidad/tareas'], "tarea:{$t->id}:".($vencida ? 'vencida' : 'proxima'));
        }

        foreach (DB::table('documentos')->where('establecimiento_id', $est->id)->where('estado', 'vigente')->whereNotNull('vence_en')
            ->where('vence_en', '<=', $hoy->copy()->addDays(30)->toDateString())->where('vence_en', '>=', $hoy->copy()->subDays(1)->toDateString())->get() as $d) {
            $dias = (int) $hoy->diffInDays(Carbon::parse($d->vence_en), false);
            $hito = $dias <= 0 ? ($dias < 0 ? 'vencido' : '0') : ($dias <= 7 ? '7' : '30');
            $n += $this->notificador->aRoles($est->id, self::GESTION, 'documento_vence',
                $hito === 'vencido' ? "Venció: {$d->titulo}" : ($hito === '0' ? "Vence hoy: {$d->titulo}" : "{$d->titulo} vence en {$dias} días"),
                'Pedí el nuevo certificado o constancia y cargá la versión nueva.', ['documento_id' => $d->id, 'ruta' => "/documentos/{$d->id}"],
                "documento:{$d->id}:v{$d->version_vigente}:{$hito}");
        }

        foreach ($this->repro->partos($est, ['hasta' => $hoy->copy()->addDays(60)->toDateString()]) as $p) {
            $f = $p['fila'];
            $aviso = (int) (Parametros::valor('parto_proximo_aviso_dias', $f->especie_codigo, $f->raza_codigo, $est->id) ?? 15);
            $ident = $f->identificacion_principal ?? 'Hembra';
            if ($p['vencido']) {
                $n += $this->notificador->aRoles($est->id, self::CAMPO, 'parto_vencido', "{$ident}: parto vencido sin registrar",
                    "La fecha probable era {$p['fecha_probable_parto']}.", ['animal_id' => $f->animal_id, 'ruta' => '/reproduccion/partos'], "parto:{$f->animal_id}:{$p['fecha_probable_parto']}:vencido");
            } elseif ($p['dias_para_parto'] >= 0 && $p['dias_para_parto'] <= $aviso) {
                $n += $this->notificador->aRoles($est->id, self::CAMPO, 'parto_proximo', "{$ident}: parto probable en {$p['dias_para_parto']} días",
                    "Fecha probable {$p['fecha_probable_parto']}.", ['animal_id' => $f->animal_id, 'ruta' => '/reproduccion/partos'], "parto:{$f->animal_id}:{$p['fecha_probable_parto']}:proximo");
            }
        }

        $recat = DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->whereNotNull('categoria_sugerida_codigo')
            ->whereColumn('categoria_sugerida_codigo', '<>', 'categoria_codigo')->count();
        if ($recat > 0) {
            $n += $this->notificador->aRoles($est->id, ['encargado'], 'recategorizacion', "{$recat} ".($recat === 1 ? 'animal' : 'animales').' con categoría sugerida',
                'Revisalos y confirmá en bloque.', ['ruta' => '/hacienda?sugerencia_categoria=true'], "recat:{$est->id}:{$h}");
        }

        if (DB::getSchemaBuilder()->hasTable('contratos_futuro')) {
            foreach (DB::table('contratos_futuro')->where('establecimiento_id', $est->id)->whereIn('estado', ['publicado', 'tomado', 'cerrado'])
                ->where('fecha_entrega', '<=', $hoy->copy()->addDays(15)->toDateString())->where('fecha_entrega', '>=', $h)->get() as $c) {
                $n += $this->notificador->aRoles($est->id, self::GESTION, 'entrega_proxima', 'Entrega de contrato en '.((int) $hoy->diffInDays(Carbon::parse($c->fecha_entrega))).' días',
                    "Contrato {$c->codigo}: entrega el {$c->fecha_entrega}.", ['contrato_id' => $c->id, 'ruta' => "/comercial/contratos/{$c->id}"], "entrega:{$c->id}:15");
            }
        }

        return $n;
    }
}
