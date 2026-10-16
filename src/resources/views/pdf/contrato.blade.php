@php $m = fn ($v) => $t['moneda'].' '.number_format((float) $v, 2, ',', '.'); @endphp
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Contrato a futuro {{ $c->codigo_publico }}</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1f2a1f; margin: 32px; font-size: 13px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 14px; margin: 20px 0 6px; text-transform: uppercase; color: #5b6b5b; }
  table { width: 100%; border-collapse: collapse; } td { padding: 4px 6px; border-bottom: 1px solid #e3e6e3; } td.k { color: #5b6b5b; width: 50%; }
  .pie { margin-top: 24px; font-size: 10px; color: #5b6b5b; }
</style></head>
<body>
  <h1>Contrato a futuro {{ $c->codigo_publico }} — versión {{ $version }}</h1>
  <div>{{ $establecimiento }} · entrega {{ $t['fecha_entrega'] }} · {{ $url }}</div>
  <h2>Hacienda</h2>
  <table>
    <tr><td class="k">Cabezas</td><td>{{ $t['cabezas'] }} ({{ $t['especie'] }} · {{ $t['categoria'] }})</td></tr>
    <tr><td class="k">Peso vivo estimado a la entrega</td><td>{{ $t['peso_vivo_estimado_kg'] }} kg/cab</td></tr>
    <tr><td class="k">Rendimiento al gancho</td><td>{{ $t['rendimiento_pct'] }} %</td></tr>
    <tr><td class="k">Kg al gancho totales</td><td>{{ number_format($t['kg_gancho_totales'], 0, ',', '.') }} kg</td></tr>
    <tr><td class="k">Tokens</td><td>{{ $t['tokens_totales'] }} de {{ $t['kg_por_token'] }} kg al gancho</td></tr>
  </table>
  <h2>Precio y anticipo</h2>
  <table>
    <tr><td class="k">Precio de referencia</td><td>{{ $m($t['precio_referencia_kg']) }}/kg gancho</td></tr>
    <tr><td class="k">Descuento</td><td>{{ $t['descuento_pct'] }} %</td></tr>
    <tr><td class="k">Precio especial</td><td>{{ $m($t['precio_especial_kg']) }}/kg gancho</td></tr>
    <tr><td class="k">Anticipo</td><td>{{ $t['anticipo_pct'] }} % = {{ $m($t['anticipo_por_token']) }} por token</td></tr>
    <tr><td class="k">Confirmación del anticipo</td><td>{{ $t['toma_vencimiento_horas'] }} h (si no, la toma se libera)</td></tr>
    <tr><td class="k">Tolerancia en la entrega</td><td>±{{ $t['tolerancia_pct'] }} %</td></tr>
  </table>
  <div class="pie">El token es un registro interno no transferible. Los pagos (anticipo y saldo) se hacen fuera de TROPILLA y cada parte
    confirma lo suyo. Se liquida por los kg al gancho reales dentro de la tolerancia; fuera de ella queda a resolver entre las partes.
    Si hay mortalidad o faltante, los tokens se recalculan con motivo y se recortan prorrata (con el anticipo a devolver). Términos
    registrados con huella encadenada: TROPILLA no certifica su contenido.</div>
</body></html>
