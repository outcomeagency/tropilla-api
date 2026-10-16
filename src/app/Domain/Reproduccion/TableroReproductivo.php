<?php

namespace App\Domain\Reproduccion;

use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\ConsultasAnimales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas del módulo Reproducción (functional/04 §3, TPA-30) sobre `animales_estado`: tablero de vientres por especie y
 * calendario de partos probables. «Hoy» es el de la zona del establecimiento; aviso y tolerancia de parto vencido son
 * parámetros por especie/raza/establecimiento (`parto_proximo_aviso_dias`, `parto_vencido_tolerancia_dias`).
 */
final class TableroReproductivo
{
    /** Estados que esperan parto (tienen FPP). */
    private const ESPERAN_PARTO = ['servida', 'prenada'];

    /** @var array<string, int> */
    private array $param = [];

    /**
     * Vientres = hembras activas con categoría de vientre (`categorias.es_vientre`) o con estado reproductivo.
     *
     * @return list<array<string, mixed>>
     */
    public function tablero(Establecimiento $est, ?string $especie): array
    {
        $hoy = $this->hoy($est);
        $filas = DB::table('animales_estado as a')->leftJoin('categorias as c', 'c.codigo', '=', 'a.categoria_codigo')
            ->where('a.establecimiento_id', $est->id)->where('a.estado', 'activo')->where('a.sexo', 'H')
            ->where(fn ($q) => $q->where('c.es_vientre', true)->orWhereNotNull('a.estado_reproductivo'))
            ->when($especie, fn ($q) => $q->where('a.especie_codigo', $especie))
            ->get(['a.especie_codigo', 'a.raza_codigo', 'a.estado_reproductivo', 'a.fecha_probable_parto']);

        $especies = $especie !== null ? [$especie] : $est->especies;
        $out = [];
        foreach ($especies as $esp) {
            $deEspecie = $filas->where('especie_codigo', $esp);
            $porMes = $deEspecie->where('estado_reproductivo', 'prenada')->whereNotNull('fecha_probable_parto')
                ->groupBy(fn ($f) => substr((string) $f->fecha_probable_parto, 0, 7))->map->count()->sortKeys();
            $proximos = 0;
            $vencidos = 0;
            foreach ($deEspecie->whereIn('estado_reproductivo', self::ESPERAN_PARTO)->whereNotNull('fecha_probable_parto') as $f) {
                $dias = $this->diasParaParto($f->fecha_probable_parto, $hoy);
                if ($this->vencido($est, $f, $dias)) {
                    $vencidos++;
                } elseif ($dias >= 0 && $dias <= $this->parametro('parto_proximo_aviso_dias', $est, $f)) {
                    $proximos++;
                }
            }
            $cuenta = fn (string $estado) => $deEspecie->where('estado_reproductivo', $estado)->count();
            $out[] = [
                'especie' => $esp,
                'total_vientres' => $deEspecie->count(),
                'vacias' => $cuenta('vacia'),
                'servidas' => $cuenta('servida'),
                'prenadas' => $cuenta('prenada'),
                'paridas' => $cuenta('parida'),
                'sin_estado' => $deEspecie->whereNull('estado_reproductivo')->count(),
                'prenadas_por_mes_parto' => $porMes->map(fn ($n, $mes) => ['mes' => $mes, 'cabezas' => $n])->values()->all(),
                'partos_proximos' => $proximos,
                'partos_vencidos' => $vencidos,
            ];
        }

        return $out;
    }

    /**
     * Partos probables (servidas y preñadas con FPP), ordenados por fecha. `vencidos`: true = solo vencidos,
     * false = sin vencidos, null = todos.
     *
     * @param  array{desde?: ?string, hasta?: ?string, especie?: ?string, vencidos?: ?bool}  $f
     * @return list<array{fila: object, fecha_probable_parto: string, fpp_precision: ?string, dias_para_parto: int, vencido: bool}>
     */
    public function partos(Establecimiento $est, array $f): array
    {
        $hoy = $this->hoy($est);
        $filas = ConsultasAnimales::base($est->id)->where('a.estado', 'activo')
            ->whereIn('a.estado_reproductivo', self::ESPERAN_PARTO)->whereNotNull('a.fecha_probable_parto')
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->where('a.especie_codigo', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('a.fecha_probable_parto', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('a.fecha_probable_parto', '<=', $v))
            ->orderBy('a.fecha_probable_parto')->orderByRaw('a.identificacion_principal ASC NULLS LAST')->orderBy('a.animal_id')
            ->get();

        $out = [];
        foreach ($filas as $fila) {
            $dias = $this->diasParaParto($fila->fecha_probable_parto, $hoy);
            $vencido = $this->vencido($est, $fila, $dias);
            if (isset($f['vencidos']) && $f['vencidos'] !== $vencido) {
                continue;
            }
            $out[] = ['fila' => $fila, 'fecha_probable_parto' => $fila->fecha_probable_parto, 'fpp_precision' => $fila->fpp_precision,
                'dias_para_parto' => $dias, 'vencido' => $vencido];
        }

        return $out;
    }

    /** Parto vencido: pasó la FPP + tolerancia sin parto registrado (si se hubiera registrado, estaría «parida»). */
    private function vencido(Establecimiento $est, object $f, int $diasParaParto): bool
    {
        return -$diasParaParto > $this->parametro('parto_vencido_tolerancia_dias', $est, $f);
    }

    private function diasParaParto(string $fpp, Carbon $hoy): int
    {
        return (int) $hoy->diffInDays(Carbon::parse($fpp), false);
    }

    private function parametro(string $clave, Establecimiento $est, object $f): int
    {
        return $this->param["{$clave}|{$f->especie_codigo}|{$f->raza_codigo}"] ??= (int) Parametros::valor($clave, $f->especie_codigo, $f->raza_codigo, $est->id);
    }

    private function hoy(Establecimiento $est): Carbon
    {
        return Carbon::parse(now($est->zona_horaria)->toDateString());
    }
}
