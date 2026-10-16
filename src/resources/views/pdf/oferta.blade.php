@php
    $c = $o['contenido'];
    $L = ['es' => ['cab' => 'cabezas', 'peso' => 'Peso promedio', 'edad' => 'Edad', 'meses' => 'meses', 'sanidad' => 'Sanidad (últimos 12 meses)', 'clase' => 'Clase de faena', 'retiro' => 'Sin período de retiro vigente', 'verif' => 'Verificá la integridad de esta ficha', 'cond' => 'Condiciones', 'consultar' => 'Precio a consultar'],
          'en' => ['cab' => 'head', 'peso' => 'Average weight', 'edad' => 'Age', 'meses' => 'months', 'sanidad' => 'Health (last 12 months)', 'clase' => 'Slaughter class', 'retiro' => 'No active withdrawal period', 'verif' => 'Verify the integrity of this sheet', 'cond' => 'Conditions', 'consultar' => 'Price on request'],
          'pt' => ['cab' => 'cabeças', 'peso' => 'Peso médio', 'edad' => 'Idade', 'meses' => 'meses', 'sanidad' => 'Sanidade (últimos 12 meses)', 'clase' => 'Classe de abate', 'retiro' => 'Sem período de carência vigente', 'verif' => 'Verifique a integridade desta ficha', 'cond' => 'Condições', 'consultar' => 'Preço a consultar']][$o['locale']];
    $qr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputType' => 'svg', 'outputBase64' => true])))->render($o['verificacion_url']);
@endphp
<!doctype html>
<html lang="{{ $o['locale'] }}">
<head><meta charset="utf-8"><title>{{ $o['seo']['titulo'] }}</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1f2a1f; margin: 32px; }
  h1 { font-size: 24px; margin: 0 0 4px; } .zona { color: #5b6b5b; margin-bottom: 18px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; }
  .k { font-size: 11px; text-transform: uppercase; color: #5b6b5b; } .v { font-size: 15px; font-weight: bold; }
  .pie { margin-top: 28px; border-top: 1px solid #ccc; padding-top: 12px; font-size: 11px; color: #5b6b5b; display: flex; gap: 16px; align-items: center; }
  .pie img { width: 110px; height: 110px; } code { font-size: 9px; word-break: break-all; }
</style></head>
<body>
  <h1>{{ $c['titulo'] ?: ($c['cantidad'].' '.$L['cab']) }}</h1>
  <div class="zona">{{ $o['establecimiento']['nombre'] }} · {{ $o['establecimiento']['zona'] }}</div>
  <div class="grid">
    <div><div class="k">{{ $c['especie'] }}</div><div class="v">{{ $c['cantidad'] }} {{ $L['cab'] }} — {{ collect($c['categorias'])->map(fn ($x) => $x['cantidad'].' '.$x['nombre'])->implode(', ') }}</div></div>
    @if($c['razas'])<div><div class="k">Raza</div><div class="v">{{ collect($c['razas'])->pluck('nombre')->implode(', ') }}</div></div>@endif
    @if($c['peso'])<div><div class="k">{{ $L['peso'] }}</div><div class="v">{{ $c['peso']['promedio_kg'] }} kg ({{ $c['peso']['metodo'] }}, {{ $c['peso']['fecha'] }})@if($c['peso']['min_kg']) · {{ $c['peso']['min_kg'] }}–{{ $c['peso']['max_kg'] }} kg @endif</div></div>@endif
    @if($c['edad'])<div><div class="k">{{ $L['edad'] }}</div><div class="v">{{ $c['edad']['min_meses'] }}–{{ $c['edad']['max_meses'] }} {{ $L['meses'] }}</div></div>@endif
    @if($c['clase_faena'])<div><div class="k">{{ $L['clase'] }}</div><div class="v">{{ $c['clase_faena']['abreviatura'] }} {{ $c['clase_faena']['clase'] }}</div></div>@endif
    @if($c['sanidad'])<div><div class="k">{{ $L['sanidad'] }}</div><div class="v">{{ collect($c['sanidad'])->map(fn ($s) => ($s['enfermedad'] ?? $s['tipo']).' '.$s['fecha'])->implode(' · ') }}@if($c['sin_retiro_vigente']) — {{ $L['retiro'] }}@endif</div></div>@endif
    <div><div class="k">{{ $L['cond'] }}</div><div class="v">{{ $c['condiciones']['modalidad'] ?? '' }} · {{ isset($c['condiciones']['precio_kg']) ? ($c['condiciones']['moneda'].' '.$c['condiciones']['precio_kg'].'/kg') : $L['consultar'] }}</div></div>
  </div>
  @if($c['descripcion'])<p>{{ $c['descripcion'] }}</p>@endif
  <div class="pie">
    <img src="{{ $qr }}" alt="QR">
    <div><strong>{{ $L['verif'] }}</strong><br>{{ $o['verificacion_url'] }}<br><code>{{ $o['huella'] }}</code><br><br>{{ $o['leyenda'] }}</div>
  </div>
</body></html>
