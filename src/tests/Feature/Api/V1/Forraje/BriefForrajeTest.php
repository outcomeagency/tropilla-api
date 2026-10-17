<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function existencia(string $estId, string $especie, string $categoria, int $cabezas): void
{
    DB::table('existencias')->insert(['establecimiento_id' => $estId, 'especie_codigo' => $especie, 'categoria_codigo' => $categoria,
        'cabezas' => $cabezas, 'individuales' => 0, 'anonimas' => $cabezas, 'actualizado_en' => '2026-10-19 12:00:00']);
}

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->est = crearEstablecimiento(extra: ['superficie_ha' => '500']);
    DB::table('establecimientos')->where('id', $this->est->id)->update(['ficha_ampliada' => json_encode([
        'capacidad_carga' => ['carga_ug_ha' => '0.6', 'periodo' => 'Promedio 2024–2026', 'criterio' => 'EV por superficie ganadera'],
    ])]);
    existencia($this->est->id, 'BOV', 'BOV_VACA', 300);
    existencia($this->est->id, 'BOV', 'BOV_TERNERO', 100);
    $this->base = "/api/v1/establecimientos/{$this->est->id}/forraje";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->asesor = miembro($this->est, 'asesor');
    $this->enc = miembro($this->est, 'encargado');
    $this->lectura = miembro($this->est, 'lectura');
});

it('expone los insumos: inventario declarado con EV, superficie, receptividad de la ficha y supuestos con fuente', function () {
    $d = $this->getJson("{$this->base}/insumos")->assertOk()->json('data');

    expect($d['inventario'])->toHaveCount(2)
        ->and(collect($d['inventario'])->firstWhere('categoria', 'BOV_VACA'))->toMatchArray(['cabezas' => 300, 'ev_por_cabeza' => '1', 'ev' => '300', 'ev_origen' => 'parametro'])
        ->and(collect($d['inventario'])->firstWhere('categoria', 'BOV_TERNERO')['ev'])->toBe('40')
        ->and($d['superficie'])->toMatchArray(['valor' => '500', 'origen' => 'establecimiento'])
        ->and($d['receptividad'])->toMatchArray(['valor' => '0.6', 'origen' => 'ficha', 'periodo' => 'Promedio 2024–2026'])
        ->and($d['supuestos']['consumo_ms_kg_ev_dia'])->toMatchArray(['valor' => '10', 'origen' => 'parametro', 'validado' => false])
        ->and($d['supuestos']['consumo_ms_kg_ev_dia']['fuente'])->toContain('Orientativo')
        ->and($d['clima']['disponible'])->toBeFalse()
        ->and($d['aviso'])->toContain('no una recomendación de manejo');
});

it('calcula escenarios con rango de incertidumbre, contexto climático de TPA-70 y preguntas para el asesor sin recetar manejo', function () {
    DB::table('clima_estado')->insert(['establecimiento_id' => $this->est->id, 'precision' => 'establecimiento', 'zona' => 'Mercedes, Corrientes',
        'pronostico' => '[]', 'lluvia' => json_encode(['ventana_dias' => 60, 'anomalia_pct' => 35, 'acumulada_mm' => 70, 'normal_mm' => 200]),
        'enso' => json_encode(['fase' => 'la_nina', 'oni' => -0.7]), 'errores' => '[]', 'calculado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('clima_alertas')->insert(['id' => (string) Str::uuid7(), 'establecimiento_id' => $this->est->id, 'regla_codigo' => 'sequia_deficit', 'tipo' => 'sequia',
        'severidad' => 'moderada', 'titulo' => 'Déficit de lluvia en la zona', 'detalle' => 'x', 'recomendacion' => 'x', 'fuente' => 'open_meteo_archive',
        'fuente_url' => 'https://open-meteo.com', 'zona' => 'Mercedes', 'desde' => '2026-10-20', 'hasta' => '2026-10-26', 'horizonte_dias' => 7,
        'estado' => 'activa', 'emitida_en' => now(), 'calculada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->postJson("{$this->base}/briefs/calcular", ['horizonte_dias' => 90, 'reservas' => ['rollos' => ['cantidad' => 100]]])->assertOk()->json('data');

    // EV 340; base: 0,6 × 500 = 300 EV de referencia → 40 EV de diferencia → 400 kg MS/día; reservas 100 × 450 × 85 % × 80 % = 30.600 kg MS.
    expect($r['inventario'])->toMatchArray(['origen' => 'existencias', 'cabezas' => 400, 'ev' => '340'])
        ->and($r['carga_declarada_ev_ha'])->toBe('0.68')
        ->and($r['reservas']['ms_utilizable_kg'])->toBe('30600')
        ->and($r['reservas']['cobertura_rodeo_completo'])->toBe(['dias' => 9, 'dias_min' => 7, 'dias_max' => 11]);
    $e = collect($r['escenarios'])->keyBy('codigo');
    expect($e['base'])->toMatchArray(['estado' => 'parcial', 'ev_referencia' => '300', 'diferencia_ev' => '40', 'faltante_ms_kg_dia' => '400',
        'faltante_ms_kg_horizonte' => '36000', 'balance_reservas_kg' => '-5400', 'cobertura' => ['dias' => 76, 'dias_min' => 63, 'dias_max' => 95]])
        ->and($e['moderado'])->toMatchArray(['reduccion_oferta_pct' => '25', 'ev_referencia' => '225', 'cobertura' => ['dias' => 26, 'dias_min' => 22, 'dias_max' => 33]])
        ->and($e['severo']['estado'])->toBe('parcial')
        ->and($r['escenario_cercano_al_clima']['codigo'])->toBe('severo')
        ->and($r['clima'])->toMatchArray(['disponible' => true, 'zona' => 'Mercedes, Corrientes'])
        ->and($r['clima']['alertas'][0]['tipo'])->toBe('sequia')
        ->and($r['incertidumbre']['margen_pct'])->toBe('20')
        ->and($r['faltantes'])->toBe([])
        ->and($r['piloto'])->toBeTrue()
        ->and(implode(' ', $r['preguntas_asesor']))->toContain('Déficit de lluvia en la zona')->toContain('receptividad declarada');
    // Lenguaje: nunca imperativos de manejo.
    expect(json_encode($r, JSON_UNESCAPED_UNICODE))->not->toContain('Debés')->not->toContain('carga óptima')->not->toContain('Reducí');
});

it('permite explorar con inventario, superficie y supuestos propios, y avisa lo que falta', function () {
    DB::table('establecimientos')->where('id', $this->est->id)->update(['superficie_ha' => null, 'ficha_ampliada' => '{}']);

    $sin = $this->postJson("{$this->base}/briefs/calcular", [])->assertOk()->json('data');
    expect(collect($sin['faltantes'])->pluck('campo')->all())->toBe(['superficie_ha', 'receptividad_ev_ha', 'reservas'])
        ->and(collect($sin['escenarios'])->pluck('estado')->unique()->all())->toBe(['sin_datos']);

    $r = $this->postJson("{$this->base}/briefs/calcular", [
        'superficie_ha' => 400, 'receptividad_ev_ha' => 0.8, 'inventario' => [['categoria' => 'BOV_VACA', 'cabezas' => 250]],
        'reservas' => ['silo' => ['toneladas' => 100]], 'supuestos' => ['consumo_ms_kg_ev_dia' => 12, 'reduccion_moderado_pct' => 30],
    ])->assertOk()->json('data');
    $consumo = collect($r['supuestos'])->firstWhere('clave', 'consumo_ms_kg_ev_dia');
    expect($r['inventario'])->toMatchArray(['origen' => 'brief', 'cabezas' => 250, 'actualizado_en' => null])
        ->and($r['superficie']['origen'])->toBe('brief')
        ->and($consumo)->toMatchArray(['valor' => '12', 'origen' => 'brief'])
        ->and(collect($r['escenarios'])->firstWhere('codigo', 'base')['estado'])->toBe('sin_faltante')
        ->and(collect($r['escenarios'])->firstWhere('codigo', 'moderado')['ev_referencia'])->toBe('224')
        ->and($r['reservas']['ms_utilizable_kg'])->toBe('28000');
});

it('usa el coeficiente EV sobrescrito por el establecimiento', function () {
    $this->putJson("/api/v1/establecimientos/{$this->est->id}/parametros/forraje_ev_categoria", ['especie' => 'BOV', 'valor' => [
        'BOV_TERNERO' => 0.5, 'BOV_TERNERA' => 0.5, 'BOV_NOVILLITO' => 0.65, 'BOV_NOVILLO' => 0.8, 'BOV_VAQUILLONA' => 0.7, 'BOV_VACA' => 1.1, 'BOV_TORITO' => 0.9, 'BOV_TORO' => 1.3, 'BOV_BUEY' => 1.0,
    ]])->assertOk();

    $d = $this->getJson("{$this->base}/insumos")->assertOk()->json('data');
    expect(collect($d['inventario'])->firstWhere('categoria', 'BOV_VACA'))->toMatchArray(['ev' => '330', 'ev_origen' => 'establecimiento']);
});

it('guarda el brief inmutable, lo lista y registra la revisión del asesor (piloto)', function () {
    Sanctum::actingAs($this->enc);
    $b = $this->postJson("{$this->base}/briefs", ['nombre' => 'Primavera 2026', 'reservas' => ['rollos' => ['cantidad' => 80, 'kg_por_rollo' => 400]]])
        ->assertCreated()->json('data');
    expect($b)->toMatchArray(['nombre' => 'Primavera 2026', 'horizonte_dias' => 90, 'estado_revision' => 'sin_revisar', 'revisado_por_asesor' => false])
        ->and($b['entradas']['reservas']['rollos']['cantidad'])->toBe(80)
        ->and($b['resultado']['escenarios'])->toHaveCount(3);
    expect(fn () => DB::transaction(fn () => DB::table('forraje_briefs')->where('id', $b['id'])->update(['nombre' => 'x'])))->toThrow(Exception::class);

    // el encargado no revisa; el asesor sí y queda su rol
    $this->postJson("{$this->base}/briefs/{$b['id']}/revisiones", ['resultado' => 'revisado', 'nota' => 'Supuestos razonables para la época.'])->assertForbidden();
    Sanctum::actingAs($this->asesor);
    $this->postJson("{$this->base}/briefs/{$b['id']}/revisiones", ['resultado' => 'otro', 'nota' => 'corta'])->assertUnprocessable()->assertJsonValidationErrors(['resultado', 'nota']);
    $rev = $this->postJson("{$this->base}/briefs/{$b['id']}/revisiones", ['resultado' => 'con_observaciones', 'nota' => 'Bajar el aprovechamiento de rollos al 70 %: están a la intemperie.'])
        ->assertCreated()->json('data');
    expect($rev)->toMatchArray(['estado_revision' => 'con_observaciones', 'revisado_por_asesor' => true])
        ->and($rev['revisiones'][0])->toMatchArray(['rol' => 'asesor', 'resultado' => 'con_observaciones']);

    // insumos recuerdan las reservas del último brief
    expect($this->getJson("{$this->base}/insumos")->json('data.reservas_ultimo_brief.rollos.cantidad'))->toBe(80);

    Sanctum::actingAs($this->lectura);
    $lista = $this->getJson("{$this->base}/briefs")->assertOk()->json('data');
    expect($lista)->toHaveCount(1)->and($lista[0])->not->toHaveKey('resultado')->and($lista[0]['estado_revision'])->toBe('con_observaciones');
    $this->getJson("{$this->base}/briefs/{$b['id']}")->assertOk()->assertJsonPath('data.revisiones.0.rol', 'asesor');
    $this->postJson("{$this->base}/briefs", [])->assertForbidden();
    $this->getJson("{$this->base}/briefs/".Str::uuid7())->assertNotFound();
});

it('valida la entrada (422) y responde 404 en un establecimiento ajeno', function () {
    $this->postJson("{$this->base}/briefs/calcular", ['horizonte_dias' => 1000, 'inventario' => [['categoria' => 'BOV_VACA', 'cabezas' => -1]], 'supuestos' => ['otro' => 1]])
        ->assertUnprocessable()->assertJsonValidationErrors(['horizonte_dias', 'inventario.0.cabezas', 'supuestos']);
    $this->postJson("{$this->base}/briefs/calcular", ['inventario' => [['categoria' => 'BOV_VACA', 'cabezas' => 1], ['categoria' => 'BOV_VACA', 'cabezas' => 2]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['inventario']);

    $ajeno = crearEstablecimiento(extra: ['nombre' => 'Otro campo']);
    DB::table('establecimientos')->where('id', $ajeno->id)->update(['especies' => '{BOV}']);
    $otra = "/api/v1/establecimientos/{$ajeno->id}/forraje";
    $this->getJson("{$otra}/insumos")->assertNotFound();
    $this->postJson("{$otra}/briefs", [])->assertNotFound();
    $this->postJson("{$otra}/briefs/calcular", ['inventario' => [['categoria' => 'BUB_BUFALA', 'cabezas' => 1]]])->assertNotFound();

    Sanctum::actingAs(duenoDe($ajeno));
    $this->postJson("{$otra}/briefs/calcular", ['inventario' => [['categoria' => 'BUB_BUFALA', 'cabezas' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['inventario']);
});
