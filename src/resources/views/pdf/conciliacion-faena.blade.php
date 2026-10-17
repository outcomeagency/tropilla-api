@php
    $o = $d['oficial']; $c = $d['conciliacion']; $l = $d['liquidacion'];
    $f = fn (?string $x) => $x === null ? '—' : \Illuminate\Support\Carbon::parse($x)->format('d/m/Y');
    $kg = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.').' kg';
    $mon = $c['importe']['moneda'] ?? 'ARS';
    $m = fn ($v) => $v === null ? '—' : $mon.' '.number_format((float) $v, 2, ',', '.');
    $estado = ['conciliado' => 'Conciliado', 'faltante' => 'Faltante', 'no_estaba_en_la_venta' => 'No estaba en la venta'];
@endphp
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Conciliación de faena — tropa {{ $o['tropa_numero'] ?? 's/n' }}</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1f2a1f; margin: 28px; font-size: 12px; }
  h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; color: #5b6b5b; letter-spacing: .03em; }
  table { width: 100%; border-collapse: collapse; } th { text-align: left; color: #5b6b5b; font-weight: normal; border-bottom: 1px solid #c9cfc9; padding: 4px 6px; }
  td { padding: 4px 6px; border-bottom: 1px solid #e3e6e3; } td.k { color: #5b6b5b; width: 40%; } td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
  .nota { font-size: 10px; color: #5b6b5b; margin: 2px 0 6px; } .alerta { border: 1px solid #c9a227; background: #fbf6e6; padding: 6px 10px; margin: 4px 0; }
  .falta { color: #8a2a1f; font-weight: bold; } .pie { margin-top: 18px; font-size: 10px; color: #5b6b5b; }
</style></head>
<body>
  <h1>Resultado de faena y conciliación</h1>
  <div>{{ $est->nombre }} · {{ $o['frigorifico'] }} · faena {{ $f($o['fecha_faena']) }} · tropa {{ $o['tropa_numero'] ?? 's/n' }}@if($o['romaneo_numero']) · romaneo {{ $o['romaneo_numero'] }}@endif</div>

  <h2>Dato del frigorífico</h2>
  <div class="nota">{{ $o['leyenda'] }} {{ $d['origen_leyenda'] }}.</div>
  <table>
    <tr><th>Garrón</th><th>Identificación</th><th class="n">Media A</th><th class="n">Media B</th><th class="n">Res</th><th>Categoría</th><th>Tipificación</th><th>Decomiso</th></tr>
    @foreach($o['reses'] as $x)
      <tr><td>{{ $x['garron'] }}</td><td>{{ $x['identificacion'] ?? '—' }}</td><td class="n">{{ $kg($x['media_a_kg']) }}</td><td class="n">{{ $kg($x['media_b_kg']) }}</td>
        <td class="n">{{ $kg($x['peso_kg']) }}</td><td>{{ $x['categoria'] ?? '—' }}</td><td>{{ $x['tipificacion'] ?? '—' }}</td><td>{{ $x['decomiso'] ?? '—' }}</td></tr>
    @endforeach
    <tr><td colspan="4"><strong>{{ $o['totales']['reses'] }} reses</strong></td><td class="n"><strong>{{ $kg($o['totales']['kg']) }}</strong></td><td colspan="3"></td></tr>
  </table>
  @if($l)
    <h2>Liquidación (como la emitió el frigorífico o consignataria)</h2>
    <table>
      <tr><td class="k">N.º / fecha</td><td>{{ $l['numero'] ?? '—' }} · {{ $f($l['fecha'] ?? null) }}</td></tr>
      <tr><td class="k">Kg liquidados × precio</td><td>{{ $kg($l['kg'] ?? null) }} × {{ $m($l['precio_kg'] ?? null) }}</td></tr>
      <tr><td class="k">Bruto</td><td>{{ $m($l['bruto'] ?? null) }}</td></tr>
      @foreach($l['deducciones'] ?? [] as $x)<tr><td class="k">— {{ $x['concepto'] }}</td><td>{{ $m($x['importe']) }}</td></tr>@endforeach
      <tr><td class="k">Neto</td><td><strong>{{ $m($l['neto'] ?? null) }}</strong></td></tr>
    </table>
  @endif

  <h2>Conciliación (cálculo de TROPILLA)</h2>
  <div class="nota">{{ $c['leyenda'] }}</div>
  @foreach($c['avisos'] as $a)<div class="alerta">{{ $a }}</div>@endforeach
  <table>
    <tr><td class="k">Cabezas enviadas / reses</td><td>{{ $c['cabezas']['enviadas'] ?? '—' }} / {{ $c['cabezas']['reses'] }} · {{ $c['cabezas']['conciliadas'] }} conciliadas, {{ $c['cabezas']['faltantes'] }} faltantes, {{ $c['cabezas']['sin_asociar'] }} sin asociar</td></tr>
    <tr><td class="k">Kg vivos de la venta / kg de reses</td><td>{{ $kg($c['kg']['vivo']) }} / {{ $kg($c['kg']['reses']) }}</td></tr>
    <tr><td class="k">Rinde de la tropa</td><td>{{ $c['rinde_pct'] !== null ? str_replace('.', ',', $c['rinde_pct']).' %' : '—' }}</td></tr>
    <tr><td class="k">Importe esperado al gancho (precio pactado)</td><td>{{ $m($c['importe']['esperado_al_gancho']) }}</td></tr>
    <tr><td class="k">Bruto liquidado − esperado</td><td>{{ $m($c['importe']['diferencia_bruto_vs_esperado']) }}</td></tr>
  </table>
  @if(count($c['animales']))
    <table style="margin-top:8px">
      <tr><th>Animal</th><th>Garrón</th><th class="n">Último peso vivo</th><th class="n">Res</th><th class="n">Rinde</th><th>Estado</th></tr>
      @foreach($c['animales'] as $a)
        <tr><td>{{ $a['identificacion'] ?? '—' }}</td><td>{{ $a['garron'] ?? '—' }}</td><td class="n">{{ $kg($a['peso_vivo_kg']) }}</td><td class="n">{{ $kg($a['peso_res_kg']) }}</td>
          <td class="n">{{ $a['rinde_pct'] !== null ? str_replace('.', ',', $a['rinde_pct']).' %' : '—' }}</td><td class="{{ $a['estado'] === 'faltante' ? 'falta' : '' }}">{{ $estado[$a['estado']] }}</td></tr>
      @endforeach
    </table>
  @endif
  <div class="pie">Documento privado generado por TROPILLA. El romaneo y la liquidación son del frigorífico; la conciliación es un cálculo orientativo
    con los datos cargados por el establecimiento y no reemplaza la documentación comercial ni fiscal.</div>
</body></html>
