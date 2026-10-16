<?php

namespace App\Domain\Contratos;

use App\Domain\Catalogos\Parametros;
use App\Support\Decimales as D;
use App\Support\Formato;

/**
 * Términos de un contrato a futuro (modelo-datos §9.5, TPA-56). Decimales como string, enteros como int (canonicalizables):
 *   kg_gancho_totales = floor(cabezas × peso_vivo_estimado_kg × rendimiento_pct / 100)
 *   tokens_totales    = floor(kg_gancho_totales / kg_por_token)       (el resto queda del productor, duda 5)
 *   precio_especial   = round2(precio_referencia_kg × (1 − descuento_pct / 100))
 *   anticipo_por_token = round2(kg_por_token × precio_especial × anticipo_pct / 100)
 * Defaults como datos: rendimiento `rendimiento_gancho_ref_pct` (BUB 53 NEA), kg por token `token_kg_default` (100),
 * vencimiento de la toma `toma_vencimiento_horas` (48), tolerancia `entrega_tolerancia_pct` (5).
 */
final class Terminos
{
    /**
     * @param  array<string, mixed>  $in  ContratoInput validado
     * @return array<string, mixed>
     */
    public static function calcular(array $in, string $especie, string $categoria, int $cabezas, string $establecimientoId): array
    {
        $rend = (string) ($in['rendimiento_pct'] ?? Parametros::valor('rendimiento_gancho_ref_pct', $especie, null, $establecimientoId) ?? '53');
        $kgToken = (int) ($in['kg_por_token'] ?? Parametros::valor('token_kg_default', null, null, $establecimientoId) ?? 100);
        $kg = (int) bcdiv(bcmul(bcmul((string) $cabezas, (string) $in['peso_vivo_estimado_kg'], 10), $rend, 10), '100', 0);
        $precio = D::redondear(D::mul((string) $in['precio_referencia_kg'], D::sub('1', D::div((string) $in['descuento_pct'], '100'))), 2);

        return [
            'cabezas' => $cabezas, 'especie' => $especie, 'categoria' => $categoria,
            'peso_vivo_estimado_kg' => (string) Formato::decimal((string) $in['peso_vivo_estimado_kg']), 'rendimiento_pct' => (string) Formato::decimal($rend),
            'kg_gancho_totales' => $kg, 'kg_por_token' => $kgToken, 'tokens_totales' => intdiv($kg, $kgToken),
            'precio_referencia_kg' => (string) Formato::decimal((string) $in['precio_referencia_kg']),
            'descuento_pct' => (string) Formato::decimal((string) $in['descuento_pct']), 'precio_especial_kg' => $precio,
            'anticipo_pct' => (string) Formato::decimal((string) $in['anticipo_pct']),
            'anticipo_por_token' => D::redondear(D::pct(D::mul((string) $kgToken, $precio), (string) $in['anticipo_pct']), 2),
            'moneda' => $in['moneda'] ?? 'ARS', 'fecha_entrega' => $in['fecha_entrega'],
            'tolerancia_pct' => (string) Formato::decimal((string) ($in['tolerancia_pct'] ?? Parametros::valor('entrega_tolerancia_pct', null, null, $establecimientoId) ?? '5')),
            'toma_vencimiento_horas' => (int) ($in['toma_vencimiento_horas'] ?? Parametros::valor('toma_vencimiento_horas', null, null, $establecimientoId) ?? 48),
        ];
    }

    /**
     * Recorte prorrata por mortalidad/faltante (§9.5): (1) se liberan tomas no confirmadas, de la más nueva a la más vieja, hasta entrar;
     * (2) si las confirmadas siguen superando T, cada una queda en floor(t_i × T / S) y el resto se reparte de a 1 por mayor parte
     * fraccionaria (empate: la más antigua). Devuelve el plan sin aplicarlo.
     *
     * @param  list<object>  $tomas  activas (reservada/pago_informado/confirmada) con id, tokens_vigentes, estado, created_at
     * @return array{liberar: list<string>, recortes: list<array{toma_id: string, de: int, a: int}>}
     */
    public static function recorte(array $tomas, int $total): array
    {
        $comprometidos = array_sum(array_map(fn ($t) => (int) $t->tokens_vigentes, $tomas));
        $liberar = [];
        if ($comprometidos <= $total) {
            return ['liberar' => [], 'recortes' => []];
        }
        $noConfirmadas = array_values(array_filter($tomas, fn ($t) => ! in_array($t->estado, ['confirmada', 'devuelta_parcial'], true)));
        usort($noConfirmadas, fn ($a, $b) => [$b->created_at, $b->id] <=> [$a->created_at, $a->id]);
        foreach ($noConfirmadas as $t) {
            if ($comprometidos <= $total) {
                break;
            }
            $liberar[] = $t->id;
            $comprometidos -= (int) $t->tokens_vigentes;
        }
        if ($comprometidos <= $total) {
            return ['liberar' => $liberar, 'recortes' => []];
        }
        $conf = array_values(array_filter($tomas, fn ($t) => in_array($t->estado, ['confirmada', 'devuelta_parcial'], true)));
        usort($conf, fn ($a, $b) => [$a->created_at, $a->id] <=> [$b->created_at, $b->id]);
        $s = array_sum(array_map(fn ($t) => (int) $t->tokens_vigentes, $conf));
        $nuevo = [];
        $frac = [];
        foreach ($conf as $i => $t) {
            $num = (int) $t->tokens_vigentes * $total;
            $nuevo[$i] = intdiv($num, $s);
            $frac[$i] = $num % $s;
        }
        $resto = $total - array_sum($nuevo);
        $orden = array_keys($conf);
        usort($orden, fn ($a, $b) => [$frac[$b], $a] <=> [$frac[$a], $b]);
        foreach (array_slice($orden, 0, $resto) as $i) {
            $nuevo[$i]++;
        }
        $recortes = [];
        foreach ($conf as $i => $t) {
            if ($nuevo[$i] !== (int) $t->tokens_vigentes) {
                $recortes[] = ['toma_id' => $t->id, 'de' => (int) $t->tokens_vigentes, 'a' => $nuevo[$i]];
            }
        }

        return ['liberar' => $liberar, 'recortes' => $recortes];
    }
}
