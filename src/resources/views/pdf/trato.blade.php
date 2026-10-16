@php
    $t = $op['trato']; $l = $op['liquidacion']; $z = $op['trazabilidad'];
    $m = fn ($v) => $v === null ? '—' : $op['moneda'].' '.number_format((float) $v, 2, ',', '.');
    $kg = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.').' kg';
@endphp
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Resumen del trato — {{ $op['tipo'] }} {{ $op['fecha'] }}</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1f2a1f; margin: 32px; font-size: 13px; }
  h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 14px; margin: 20px 0 6px; text-transform: uppercase; color: #5b6b5b; }
  table { width: 100%; border-collapse: collapse; } td { padding: 4px 6px; border-bottom: 1px solid #e3e6e3; } td.k { color: #5b6b5b; width: 45%; }
  .neto td { font-weight: bold; font-size: 15px; } .pie { margin-top: 24px; font-size: 10px; color: #5b6b5b; } code { font-size: 9px; }
</style></head>
<body>
  <h1>Resumen del trato — {{ ucfirst($op['tipo']) }}</h1>
  <div>{{ $op['establecimiento'] }} · {{ $op['fecha'] }} · Operación {{ $op['id'] }} · versión {{ $op['version'] }}</div>
  <h2>Condiciones</h2>
  <table>
    <tr><td class="k">Contraparte</td><td>{{ $t['comprador'] ?? '—' }}</td></tr>
    <tr><td class="k">Destino</td><td>{{ $t['destino'] ?? '—' }}</td></tr>
    <tr><td class="k">Hacienda</td><td>{{ $op['cantidad'] }} × {{ $op['categoria_nombre'] }} ({{ $op['especie'] }})</td></tr>
    <tr><td class="k">Modalidad</td><td>{{ $t['modalidad'] === 'al_gancho' ? 'Al gancho' : 'En pie' }}</td></tr>
    <tr><td class="k">Lugar y hora de pesada</td><td>{{ $t['lugar_pesada'] ?? '—' }} {{ $t['hora_pesada'] ?? '' }}</td></tr>
    <tr><td class="k">Desbaste pactado</td><td>{{ isset($t['desbaste_pct']) ? $t['desbaste_pct'].' %' : '—' }}</td></tr>
    <tr><td class="k">Tolerancias</td><td>{{ $t['tolerancias'] ?? '—' }}</td></tr>
    <tr><td class="k">Plazo de pago</td><td>{{ isset($t['plazo_pago_dias']) ? $t['plazo_pago_dias'].' días' : '—' }}</td></tr>
    <tr><td class="k">Flete a cargo de</td><td>{{ $t['flete_a_cargo'] ?? '—' }}</td></tr>
    <tr><td class="k">Comisión / intermediario</td><td>{{ isset($t['comision_pct']) ? $t['comision_pct'].' %' : '—' }} {{ $t['intermediario'] ?? '' }}</td></tr>
  </table>
  <h2>Liquidación</h2>
  <table>
    <tr><td class="k">Peso bruto</td><td>{{ $kg($l['peso_bruto_kg'] ?? null) }}</td></tr>
    <tr><td class="k">Peso neto</td><td>{{ $kg($l['peso_neto_kg'] ?? null) }}</td></tr>
    @if(($t['modalidad'] ?? '') === 'al_gancho')<tr><td class="k">Rendimiento</td><td>{{ $l['rendimiento_pct'] ?? '—' }} %</td></tr>@endif
    <tr><td class="k">Kg liquidables</td><td>{{ $kg($l['kg_liquidables'] ?? null) }}</td></tr>
    <tr><td class="k">Precio por kg</td><td>{{ $m($l['precio_kg'] ?? null) }}</td></tr>
    <tr><td class="k">Subtotal</td><td>{{ $m($l['subtotal'] ?? null) }}</td></tr>
    <tr><td class="k">IVA ({{ $l['iva_pct'] ?? '' }} %) — informativo</td><td>{{ $m($l['iva'] ?? null) }}</td></tr>
    <tr><td class="k">Comisión</td><td>{{ $m($l['comision'] ?? null) }}</td></tr>
    <tr><td class="k">Flete, arranque y seguro</td><td>{{ $m($l['gastos'] ?? null) }}</td></tr>
    <tr class="neto"><td class="k">Neto total</td><td>{{ $m($l['neto_total'] ?? null) }}</td></tr>
    <tr class="neto"><td class="k">Neto por kg vivo</td><td>{{ $m($l['neto_kg_vivo'] ?? null) }}</td></tr>
  </table>
  <h2>Trazabilidad (declarada)</h2>
  <table>
    <tr><td class="k">DT-e / DTA</td><td>{{ $z['dte_numero'] ?? '—' }}</td></tr>
    <tr><td class="k">Tropa</td><td>{{ $z['tropa_numero'] ?? '—' }}</td></tr>
    <tr><td class="k">RENSPA origen / destino</td><td>{{ $z['renspa_origen'] ?? '—' }} / {{ $z['renspa_destino'] ?? '—' }}</td></tr>
  </table>
  <div class="pie">Documento privado generado por TROPILLA con lo declarado por las partes. La versión de la operación queda registrada con huella
    encadenada; este PDF se guarda en Documentos con su propia huella. No certifica el contenido ni reemplaza la documentación oficial.</div>
</body></html>
