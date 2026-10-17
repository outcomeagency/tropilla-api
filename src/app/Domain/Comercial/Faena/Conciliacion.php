<?php

namespace App\Domain\Comercial\Faena;

use App\Support\Decimales as D;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación de venta (TPA-67): **cálculo propio de TROPILLA**, separado del dato oficial del frigorífico. Compara los
 * animales enviados (venta o egreso vinculados) con las reses: conciliados, faltantes (enviados sin res), reses de animales
 * que no estaban en la venta y reses sin asociar; rinde por animal (res / último peso vivo registrado) y de la tropa
 * (kg de reses / kg vivos de la venta); y el importe esperado al gancho (kg de reses × precio pactado) contra la liquidación.
 * Nunca escribe nada: se recalcula al leer.
 */
final class Conciliacion
{
    /** @return array<string, mixed> */
    public static function de(object $r): array
    {
        $reses = DB::table('faena_reses')->where('resultado_id', $r->id)->orderBy('orden')->get();
        $op = $r->operacion_id ? DB::table('operaciones')->where('id', $r->operacion_id)->first() : null;
        $liqVenta = $op ? json_decode((string) DB::table('operacion_versiones')->where('operacion_id', $op->id)->where('accion', '<>', 'anulacion')
            ->orderByDesc('version')->value('liquidacion'), true) : null;
        $enviados = match (true) {
            $op !== null => DB::table('operacion_animales')->where('operacion_id', $op->id)->pluck('animal_id')->all(),
            $r->egreso_evento_id !== null => self::egreso($r->egreso_evento_id),
            default => [],
        };
        $cabezasEnviadas = $op ? (int) $op->cantidad : ($enviados !== [] ? count($enviados) : null);

        $porAnimal = [];
        foreach ($reses as $x) {
            if ($x->animal_id) {
                $porAnimal[$x->animal_id] = $x;
            }
        }
        $ids = array_values(array_unique([...$enviados, ...array_keys($porAnimal)]));
        $info = DB::table('animales_estado')->whereIn('animal_id', $ids)->get(['animal_id', 'identificacion_principal', 'peso_kg', 'peso_fecha', 'categoria_codigo'])->keyBy('animal_id');

        $animales = [];
        $rindes = [];
        foreach ($ids as $id) {
            $res = $porAnimal[$id] ?? null;
            $a = $info[$id] ?? null;
            $vivo = $a?->peso_kg !== null ? Formato::decimal($a->peso_kg) : null;
            $rinde = $res && $vivo !== null && D::cmp($vivo, '0') > 0 ? D::redondear(D::mul(D::div((string) $res->peso_kg, $vivo), '100'), 1) : null;
            if ($rinde !== null) {
                $rindes[] = $rinde;
            }
            $enviado = in_array($id, $enviados, true);
            $animales[] = [
                'animal_id' => $id, 'identificacion' => $a?->identificacion_principal, 'categoria' => $a?->categoria_codigo, 'enviado' => $enviado,
                'garron' => $res?->garron, 'peso_res_kg' => $res ? Formato::decimal($res->peso_kg) : null,
                'peso_vivo_kg' => $vivo, 'peso_vivo_fecha' => $a?->peso_fecha, 'rinde_pct' => $rinde,
                'estado' => match (true) {
                    $res !== null && ($enviado || $enviados === []) => 'conciliado',
                    $res !== null => 'no_estaba_en_la_venta',
                    default => 'faltante',
                },
            ];
        }
        usort($animales, fn ($a, $b) => [self::ordenEstado($a['estado']), $a['garron'] ?? 'zzz', $a['identificacion'] ?? ''] <=> [self::ordenEstado($b['estado']), $b['garron'] ?? 'zzz', $b['identificacion'] ?? '']);

        $kgReses = $reses->isEmpty() ? '0' : Faena::sumar(...$reses->pluck('peso_kg')->map(fn ($v) => (string) $v)->all());
        $kgVivo = isset($liqVenta['peso_neto_kg']) ? (string) $liqVenta['peso_neto_kg'] : (isset($liqVenta['peso_bruto_kg']) ? (string) $liqVenta['peso_bruto_kg'] : null);
        $liq = $r->liquidacion ? json_decode($r->liquidacion, true) : null;
        $alGancho = $op?->modalidad === 'al_gancho';
        $esperado = $alGancho ? D::redondear(D::mul($kgReses, (string) $op->precio_kg), 2) : null;

        $faltantes = count(array_filter($animales, fn ($a) => $a['estado'] === 'faltante'));
        $sinAsociar = $reses->filter(fn ($x) => $x->animal_id === null)->count();
        $avisos = [];
        if ($op === null && $r->egreso_evento_id === null) {
            $avisos[] = __('Vinculá la venta o el egreso para comparar animales enviados con reses pagadas.');
        }
        if ($cabezasEnviadas !== null && $cabezasEnviadas !== $reses->count()) {
            $avisos[] = __('Se enviaron :e cabezas y el frigorífico informa :r reses.', ['e' => $cabezasEnviadas, 'r' => $reses->count()]);
        }
        if ($faltantes > 0) {
            $avisos[] = $faltantes === 1 ? __('1 animal enviado no aparece en el romaneo (o su caravana no se leyó).') : __(':n animales enviados no aparecen en el romaneo (o su caravana no se leyó).', ['n' => $faltantes]);
        }
        if ($liq !== null && isset($liq['kg']) && D::cmp($liq['kg'], $kgReses) !== 0) {
            $avisos[] = __('La liquidación paga :l kg y las reses suman :r kg.', ['l' => self::num($liq['kg'], 1), 'r' => self::num($kgReses, 1)]);
        }
        if ($liq !== null && isset($liq['bruto'], $liq['kg'], $liq['precio_kg']) && D::cmp(D::redondear(D::mul($liq['kg'], $liq['precio_kg']), 2), $liq['bruto']) !== 0) {
            $avisos[] = __('En la liquidación, kg × precio no da el bruto informado.');
        }
        if ($esperado !== null && isset($liq['bruto']) && D::cmp($esperado, $liq['bruto']) !== 0) {
            $avisos[] = __('Con el precio pactado, las reses valen :e y la liquidación dice :b.', ['e' => self::num($esperado, 2), 'b' => self::num($liq['bruto'], 2)]);
        }
        $deducciones = isset($liq['deducciones']) && $liq['deducciones'] !== [] ? D::redondear(D::sum(...array_map(fn ($x) => (string) $x['importe'], $liq['deducciones'])), 2) : null;
        if ($liq !== null && isset($liq['bruto'], $liq['neto']) && $deducciones !== null && D::cmp(D::redondear(D::sub($liq['bruto'], $deducciones), 2), $liq['neto']) !== 0) {
            $avisos[] = __('Bruto menos deducciones no da el neto informado.');
        }

        return [
            'leyenda' => __(Faena::LEYENDA_CALCULO),
            'venta' => $op ? ['id' => $op->id, 'fecha' => (string) $op->fecha, 'contraparte' => $op->contraparte, 'modalidad' => $op->modalidad, 'cantidad' => (int) $op->cantidad,
                'precio_kg' => Formato::decimal($op->precio_kg), 'moneda' => $op->moneda, 'kg_vivo' => $kgVivo === null ? null : Formato::decimal($kgVivo),
                'neto_total' => Formato::decimal($op->neto_total)] : null,
            'cabezas' => ['enviadas' => $cabezasEnviadas, 'reses' => $reses->count(), 'conciliadas' => count(array_filter($animales, fn ($a) => $a['estado'] === 'conciliado')),
                'faltantes' => $faltantes, 'no_estaban_en_la_venta' => count(array_filter($animales, fn ($a) => $a['estado'] === 'no_estaba_en_la_venta')), 'sin_asociar' => $sinAsociar],
            'kg' => ['reses' => $kgReses, 'vivo' => $kgVivo === null ? null : Formato::decimal($kgVivo), 'liquidados' => $liq['kg'] ?? null,
                'diferencia_liquidados_vs_reses' => isset($liq['kg']) ? Faena::kg(D::sub($liq['kg'], $kgReses)) : null],
            'rinde_pct' => $kgVivo !== null && D::cmp($kgVivo, '0') > 0 && $reses->isNotEmpty() ? D::redondear(D::mul(D::div($kgReses, $kgVivo), '100'), 1) : null,
            'rinde_promedio_individual_pct' => $rindes === [] ? null : D::redondear(D::div(D::sum(...$rindes), (string) count($rindes)), 1),
            'importe' => ['esperado_al_gancho' => $esperado, 'liquidado_bruto' => $liq['bruto'] ?? null, 'deducciones' => $deducciones, 'liquidado_neto' => $liq['neto'] ?? null,
                'diferencia_bruto_vs_esperado' => $esperado !== null && isset($liq['bruto']) ? D::redondear(D::sub($liq['bruto'], $esperado), 2) : null,
                'moneda' => $liq['moneda'] ?? $op?->moneda ?? 'ARS'],
            'animales' => $animales,
            'avisos' => $avisos,
        ];
    }

    /** Número para leer en un aviso: separador de miles y coma decimal, sin ceros de más («1.546,5», «9.588.300»). */
    private static function num(string $v, int $decimales): string
    {
        $t = number_format((float) $v, $decimales, ',', '.');

        return str_contains($t, ',') ? rtrim(rtrim($t, '0'), ',') : $t;
    }

    private static function ordenEstado(string $e): int
    {
        return match ($e) {
            'faltante' => 0, 'no_estaba_en_la_venta' => 1, default => 2,
        };
    }

    /** @return list<string> */
    private static function egreso(string $raiz): array
    {
        $e = DB::table('eventos')->where('evento_raiz_id', $raiz)->where('en_revision', false)->orderByDesc('version')->first();

        return $e === null || $e->accion === 'anulacion' ? [] : DB::table('evento_animales')->where('evento_id', $e->id)->pluck('animal_id')->all();
    }
}
