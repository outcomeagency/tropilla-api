@php
    $f = fn (?string $d) => $d === null ? '—' : \Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
    $enf = ['brucelosis' => 'Brucelosis', 'tuberculosis' => 'Tuberculosis'];
    $est = ['libre' => 'Libre', 'en_saneamiento' => 'En saneamiento', 'en_certificacion' => 'En certificación', 'sin_estatus' => 'Sin estatus', 'suspendido' => 'Suspendido'];
    $mot = ['does' => 'DOES', 'recertificacion' => 'Recertificación', 'saneamiento' => 'Saneamiento', 'movimiento' => 'Precondición de movimiento', 'rutina' => 'Rutina', 'otro' => 'Otro'];
    $res = ['negativo' => 'Negativo', 'sospechoso' => 'Sospechoso', 'positivo' => 'Positivo', 'no_concluyente' => 'No concluyente', 'no_apto' => 'Muestra no apta'];
@endphp
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Resumen sanitario — {{ $r['establecimiento'] }}</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #1f2a1f; margin: 28px; font-size: 12px; }
  h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; color: #5b6b5b; letter-spacing: .03em; }
  h3 { font-size: 13px; margin: 12px 0 4px; }
  table { width: 100%; border-collapse: collapse; } th { text-align: left; color: #5b6b5b; font-weight: normal; border-bottom: 1px solid #c9cfc9; padding: 4px 6px; }
  td { padding: 4px 6px; border-bottom: 1px solid #e3e6e3; vertical-align: top; } td.k { color: #5b6b5b; width: 38%; }
  .aviso { border: 1px solid #c9a227; background: #fbf6e6; padding: 8px 10px; margin: 10px 0 4px; font-size: 11px; }
  .origen { font-size: 10px; color: #5b6b5b; } .pos { font-weight: bold; color: #8a2a1f; } .pie { margin-top: 20px; font-size: 10px; color: #5b6b5b; }
</style></head>
<body>
  <h1>Resumen sanitario para el veterinario</h1>
  <div>{{ $r['establecimiento'] }}@if($r['renspa']) · RENSPA {{ $r['renspa'] }}@endif · generado el {{ $f($r['fecha']) }}</div>
  <div class="aviso">{{ $r['aviso'] }} Este resumen sirve para preparar la visita: no reemplaza SIGSA ni los certificados oficiales.</div>

  @foreach($r['enfermedades'] as $e)
    <h2>{{ $enf[$e['enfermedad']] }}</h2>
    <table>
      <tr><td class="k">Estatus</td><td>
        @if($e['estatus']) {{ $est[$e['estatus']['estatus']] }} <span class="origen">({{ $e['estatus']['origen_leyenda'] }})</span>
        @else Sin datos cargados @endif
      </td></tr>
      @if($e['estatus'])
        <tr><td class="k">Certificado</td><td>{{ $e['estatus']['certificado_numero'] ?? '—' }} · {{ $e['estatus']['autoridad'] ?? '—' }} · emitido {{ $f($e['estatus']['emitido_el']) }}</td></tr>
        <tr><td class="k">Vigencia</td><td>desde {{ $f($e['estatus']['vigente_desde']) }} hasta {{ $f($e['estatus']['vence_el']) }}</td></tr>
      @endif
      <tr><td class="k">Plan de saneamiento</td><td>
        @if($e['plan_saneamiento']) Activo desde {{ $f($e['plan_saneamiento']['inicio']) }} · próximo muestreo {{ $f($e['plan_saneamiento']['proximo_muestreo']) }} · {{ $e['plan_saneamiento']['acciones_pendientes'] }} acciones pendientes
        @else No hay plan activo @endif
      </td></tr>
      <tr><td class="k">Positivos en el rodeo</td><td>{{ count($e['positivos']) === 0 ? 'Ninguno registrado' : implode(', ', array_map(fn ($p) => ($p['identificacion'] ?? 's/id').' ('.$f($p['desde']).')', $e['positivos'])) }}</td></tr>
    </table>
    @foreach($e['protocolos'] as $p)
      <h3>{{ $p['prueba_nombre'] }} · {{ $mot[$p['motivo']] }} · muestreo {{ $f($p['fecha_muestreo']) }}</h3>
      <div class="origen">Protocolo {{ $p['protocolo_numero'] ?? 's/n' }} · {{ $p['laboratorio'] ?? 'laboratorio sin dato' }} · Vet. {{ $p['veterinario']['nombre'] ?? '—' }} {{ $p['veterinario']['matricula'] ? '(Mat. '.$p['veterinario']['matricula'].')' : '' }} · {{ $p['origen_leyenda'] }}</div>
      <table>
        <tr><th>#</th><th>Identificación</th><th>Categoría</th><th>Muestra</th><th>Lecturas (mm)</th><th>Resultado</th></tr>
        @foreach($p['resultados'] as $x)
          <tr><td>{{ $x['orden'] }}</td><td>{{ $x['identificacion'] ?? '—' }}@if(! $x['animal_id']) <span class="origen">(sin asociar)</span>@endif</td>
            <td>@php($cat = $x['categoria_codigo'] ?? ($x['animal']['categoria'] ?? null)){{ $cat ? ($r['categorias'][$cat] ?? $cat) : '—' }}</td><td>{{ $x['muestra'] ?? '—' }}</td>
            <td>{{ $x['lectura_0h_mm'] !== null ? $x['lectura_0h_mm'].' → '.$x['lectura_72h_mm'] : '—' }}</td>
            <td class="{{ $x['resultado'] === 'positivo' ? 'pos' : '' }}">{{ $res[$x['resultado']] }}</td></tr>
        @endforeach
      </table>
    @endforeach
  @endforeach

  <h2>Vencimientos próximos</h2>
  <table>
    @forelse($r['vencimientos'] as $v)
      <tr><td class="k">{{ $f($v['fecha']) }}</td><td>{{ $enf[$v['enfermedad']] }} — {{ $v['titulo'] }}</td></tr>
    @empty
      <tr><td>No hay vencimientos en los próximos {{ $r['horizonte_dias'] }} días.</td></tr>
    @endforelse
  </table>
  <div class="pie">Generado por TROPILLA con datos cargados por el establecimiento. Cada dato indica si es transcripción revisada de un original adjunto
    o declaración del productor. La condición sanitaria oficial la certifica SENASA.</div>
</body></html>
