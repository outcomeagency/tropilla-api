<?php

use App\Domain\Agregados\Gobernanza;
use App\Domain\Agregados\PruebaReidentificacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->staff = User::factory()->create(['es_staff' => true]);
    $this->analista = User::factory()->create(['email' => 'analista@frigo.example']);
    Sanctum::actingAs($this->staff);
    $this->cliente = $this->postJson('/api/v1/agregados/clientes', ['nombre' => 'Frigorífico del Litoral', 'tipo' => 'frigorifico',
        'finalidad' => 'Planeamiento de abastecimiento por zona; sin reidentificación ni cruces.', 'contrato_version' => 'piloto-v0',
        'vigente_hasta' => now()->addMonths(6)->toDateString(), 'metricas' => ['existencias', 'precios'], 'consultas_por_dia' => 5])
        ->assertCreated()->assertJsonPath('data.metricas', ['existencias', 'precios'])->assertJsonPath('data.cupo_diario', 5)->json('data');
    $this->postJson("/api/v1/agregados/clientes/{$this->cliente['id']}/miembros", ['email' => 'analista@frigo.example'])->assertNoContent();
    $this->q = fn (array $extra = []) => '/api/v1/agregados/tablero?'.http_build_query(['cliente' => $this->cliente['id'], 'metrica' => 'existencias', 'especie' => 'BUB', ...$extra]);
});

it('el cliente consulta el cubo protegido: celdas chicas y despejables suprimidas, valores redondeados, fuente sintética y consulta auditada', function () {
    Sanctum::actingAs($this->analista);
    $ctx = $this->getJson('/api/v1/agregados/contexto')->assertOk()->assertJsonPath('data.acceso', true)->assertJsonPath('data.es_staff', false)
        ->assertJsonPath('data.fuente', 'sintetico')->assertJsonPath('data.opt_in_real_habilitado', false)->assertJsonCount(1, 'data.licencias')->json('data');
    expect($ctx['licencias'][0]['metricas'])->toBe(['existencias', 'precios'])->and($ctx['politica']['k_minimo'])->toBe(10);

    $r = $this->getJson(($this->q)(['escala' => 1, 'desde' => '2026T1', 'hasta' => '2026T2']))->assertOk()
        ->assertJsonPath('data.fuente', 'sintetico')->assertJsonPath('data.consulta.cupo_restante', 4)->json('data');
    expect($r['trimestres'])->toBe(['2026T1', '2026T2'])->and($r['resumen']['celdas'])->toBe(2 * 5 * 4);
    $filas = collect($r['filas']);
    foreach ($filas->where('estado', 'publicada') as $f) {
        expect($f['valores']['establecimientos'])->toBeGreaterThanOrEqual(10)->and($f['valores']['cabezas'] % 100)->toBe(0);
    }
    // Búfalos en Formosa (3 criaderos, uno dominante) nunca se publican; el total NEA sí.
    expect($filas->where('zona.codigo', 'formosa')->where('escala', 'todas')->pluck('estado')->unique()->all())->toBe(['suprimida'])
        ->and($filas->where('zona.codigo', 'nea')->where('escala', 'todas')->pluck('estado')->unique()->all())->toBe(['publicada'])
        ->and($filas->where('estado', 'suprimida')->pluck('motivo')->unique()->sort()->values()->all())->toContain('celda_chica', 'complementaria');
    // Por cada total publicado, lo que se despeja restando las partes publicadas nunca es una sola celda.
    foreach ($filas->groupBy('trimestre') as $t) {
        $nea = $t->where('zona.codigo', 'nea');
        foreach (['todas', 'chico', 'mediano', 'grande'] as $e) {
            if ($nea->firstWhere('escala', $e)['estado'] === 'publicada') {
                expect($t->where('escala', $e)->where('zona.codigo', '!=', 'nea')->where('estado', 'suprimida')->count())->not->toBe(1);
            }
        }
    }

    // Mismo cubo en cualquier recorte: la celda NEA da lo mismo pidiendo solo NEA.
    $nea = $this->getJson(($this->q)(['geo' => 'nea', 'escala' => 1, 'desde' => '2026T1', 'hasta' => '2026T2']))->assertOk()->json('data.filas');
    expect($nea)->toBe($filas->where('zona.codigo', 'nea')->values()->all());

    $c = DB::table('agregados_consultas')->where('cliente_id', $this->cliente['id'])->orderBy('created_at')->get();
    expect($c)->toHaveCount(2)->and($c[0]->usuario_id)->toBe($this->analista->id)->and($c[0]->fuente)->toBe('sintetico')
        ->and(json_decode($c[0]->parametros, true))->toMatchArray(['metrica' => 'existencias', 'especie' => 'BUB', 'escala' => true, 'desde' => '2026T1']);
    expect(fn () => DB::transaction(fn () => DB::table('agregados_consultas')->update(['celdas' => 0])))->toThrow(Exception::class);
    $this->getJson("/api/v1/agregados/consultas?cliente={$this->cliente['id']}")->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.usuario.nombre', $this->analista->nombre);
});

it('permisos de licencia: métrica no licenciada, suspendida, vencida, cupo diario y ajenos', function () {
    Sanctum::actingAs($this->analista);
    $this->getJson(($this->q)(['metrica' => 'cobertura_sanitaria']))->assertForbidden()->assertJsonPath('code', 'metrica_no_habilitada');
    $this->getJson('/api/v1/agregados/tablero?metrica=existencias&especie=BOV')->assertUnprocessable();
    $this->getJson('/api/v1/agregados/gobernanza')->assertNotFound();
    $this->getJson('/api/v1/agregados/clientes')->assertNotFound();
    $this->postJson('/api/v1/agregados/pruebas')->assertNotFound();

    foreach (range(1, 5) as $_) {
        $this->getJson(($this->q)())->assertOk();
    }
    $this->getJson(($this->q)())->assertStatus(429)->assertJsonPath('code', 'cupo_consultas_agotado');
    $this->get(str_replace('/tablero?', '/tablero/exportar?', ($this->q)()))->assertStatus(429);
    expect(DB::table('agregados_consultas')->where('cliente_id', $this->cliente['id'])->count())->toBe(5);

    Sanctum::actingAs($this->staff);
    $this->patchJson("/api/v1/agregados/clientes/{$this->cliente['id']}", ['estado' => 'suspendido', 'row_version' => 1])->assertOk()->assertJsonPath('data.estado', 'suspendido');
    $this->patchJson("/api/v1/agregados/clientes/{$this->cliente['id']}", ['estado' => 'activo', 'row_version' => 1])->assertStatus(409);
    Sanctum::actingAs($this->analista);
    $this->getJson(($this->q)())->assertForbidden()->assertJsonPath('code', 'licencia_suspendida');
    DB::table('agregados_clientes')->update(['estado' => 'activo', 'vigente_hasta' => now()->subDay()->toDateString()]);
    $this->getJson(($this->q)())->assertForbidden()->assertJsonPath('code', 'licencia_vencida');

    $ajeno = User::factory()->create();
    Sanctum::actingAs($ajeno);
    $this->getJson('/api/v1/agregados/contexto')->assertOk()->assertJsonPath('data.acceso', false)->assertJsonPath('data.licencias', []);
    $this->getJson(($this->q)())->assertNotFound();
    $this->getJson("/api/v1/agregados/consultas?cliente={$this->cliente['id']}")->assertNotFound();

    Sanctum::actingAs($this->staff);
    $this->deleteJson("/api/v1/agregados/clientes/{$this->cliente['id']}/miembros/{$this->analista->id}")->assertNoContent();
    Sanctum::actingAs($this->analista);
    $this->getJson(($this->q)())->assertNotFound();
    expect(DB::table('agregados_bitacora')->pluck('accion')->all())->toBe(['licencia_alta', 'integrante_alta', 'licencia_suspendido', 'integrante_baja']);
});

it('export CSV marcado como sintético; vista previa del equipo sin licencia queda auditada sin cupo', function () {
    Sanctum::actingAs($this->analista);
    $csv = $this->get(str_replace('/tablero?', '/tablero/exportar?', ($this->q)(['metrica' => 'precios', 'especie' => 'BOV'])))->assertOk()
        ->assertHeader('X-Tropilla-Fuente', 'sintetico')->getContent();
    $lineas = explode("\n", trim($csv));
    expect($lineas[0])->toStartWith('# TROPILLA · Prototipo con datos sintéticos')->and($lineas[1])->toBe('trimestre,zona,escala,estado,motivo,establecimientos,precio_kg')
        ->and(count($lineas))->toBe(2 + 4 * 5);

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/v1/agregados/tablero?metrica=cobertura_sanitaria&especie=BOV')->assertOk()->assertJsonPath('data.consulta.cupo_restante', null);
    expect(DB::table('agregados_consultas')->whereNull('cliente_id')->value('usuario_id'))->toBe($this->staff->id)
        ->and(DB::table('agregados_consultas')->where('tipo', 'exportacion')->exists())->toBeTrue();
    $this->getJson('/api/v1/agregados/consultas')->assertOk()->assertJsonPath('data.0.cliente', null);
});

it('gate: reidentificación exige prueba aprobada; cuatro aspectos aprobados habilitan planificar el opt-in; una política nueva lo vuelve a cerrar', function () {
    $this->postJson('/api/v1/agregados/revisiones', ['aspecto' => 'reidentificacion', 'resultado' => 'aprobado', 'nota' => 'Revisada la prueba completa.'])
        ->assertStatus(409)->assertJsonPath('code', 'prueba_pendiente');
    $p = $this->postJson('/api/v1/agregados/pruebas')->assertCreated()->assertJsonPath('data.resultado', 'aprobada')->assertJsonPath('data.fallas', 0)
        ->assertJsonCount(5, 'data.controles')->json('data');
    expect(collect($p['controles'])->pluck('codigo')->all())->toBe(['celda_chica', 'dominancia', 'diferenciacion', 'vinculacion', 'temporal']);
    foreach (Gobernanza::ASPECTOS as $a) {
        $gate = $this->postJson('/api/v1/agregados/revisiones', ['aspecto' => $a, 'resultado' => 'aprobado', 'nota' => "Aspecto {$a} revisado con el equipo."])->assertCreated()->json('data');
    }
    expect($gate['habilita_opt_in_real'])->toBeTrue()->and(DB::table('agregados_revisiones')->where('aspecto', 'reidentificacion')->value('prueba_id'))->toBe($p['id']);
    $this->getJson('/api/v1/agregados/gobernanza')->assertOk()->assertJsonPath('data.gate.habilita_opt_in_real', true)
        ->assertJsonPath('data.dataset.fuente', 'sintetico')->assertJsonPath('data.dataset.establecimientos', 182)->assertJsonPath('data.ultima_prueba.id', $p['id']);

    $this->postJson('/api/v1/agregados/politicas', ['k_minimo' => 4, 'dominancia_max_pct' => 50, 'redondeo_cabezas' => 100, 'consultas_por_dia' => 40,
        'desglose_escala' => true, 'nota' => 'Intento de bajar k.'])->assertUnprocessable();
    $this->postJson('/api/v1/agregados/politicas', ['k_minimo' => 12, 'dominancia_max_pct' => 45, 'redondeo_cabezas' => 100, 'consultas_por_dia' => 40,
        'desglose_escala' => false, 'nota' => 'Endurecer celdas antes del piloto.'])->assertCreated()->assertJsonPath('data.version', 2);
    $this->getJson('/api/v1/agregados/gobernanza')->assertJsonPath('data.gate.habilita_opt_in_real', false)->assertJsonPath('data.gate.aspectos.0.estado', 'pendiente')
        ->assertJsonCount(2, 'data.politicas');
    Sanctum::actingAs($this->analista);
    $this->getJson(($this->q)(['escala' => 1]))->assertUnprocessable()->assertJsonPath('code', 'desglose_no_permitido');
    $this->getJson(($this->q)())->assertOk()->assertJsonPath('data.politica.k_minimo', 12);
    expect(fn () => DB::transaction(fn () => DB::table('agregados_politicas')->delete()))->toThrow(Exception::class);
});

it('la prueba detecta la diferenciación por resta si se apaga la supresión complementaria', function () {
    $this->getJson('/api/v1/agregados/contexto')->assertOk();
    $r = app(PruebaReidentificacion::class)->ejecutar(Gobernanza::politica(), complementaria: false);
    $fallas = collect($r['controles'])->pluck('fallas', 'codigo');
    expect($r['resultado'])->toBe('fallida')->and($fallas['diferenciacion'])->toBeGreaterThan(0)->and($fallas['vinculacion'])->toBeGreaterThan(0)
        ->and($fallas['celda_chica'])->toBe(0);
});
