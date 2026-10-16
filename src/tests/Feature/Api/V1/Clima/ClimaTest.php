<?php

use App\Domain\Clima\EvaluadorAlertas;
use App\Domain\Notificaciones\Push\PushDriver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Pronóstico diario estilo Open-Meteo: 7 días pasados + 16 futuros; `$dias` pisa valores por índice relativo a hoy (0 = hoy). */
function pronosticoOpenMeteo(array $dias = [], string $hoy = '2026-10-20'): array
{
    $d = ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_sum' => [], 'precipitation_probability_max' => [],
        'relative_humidity_2m_min' => [], 'wind_speed_10m_max' => []];
    for ($i = -7; $i < 16; $i++) {
        $v = array_replace(['tmax' => 27, 'tmin' => 15, 'lluvia' => 0.5, 'prob' => 20, 'hr' => 55, 'viento' => 15], $dias[$i] ?? []);
        $d['time'][] = Carbon::parse($hoy)->addDays($i)->toDateString();
        $d['temperature_2m_max'][] = $v['tmax'];
        $d['temperature_2m_min'][] = $v['tmin'];
        $d['precipitation_sum'][] = $v['lluvia'];
        $d['precipitation_probability_max'][] = $v['prob'];
        $d['relative_humidity_2m_min'][] = $v['hr'];
        $d['wind_speed_10m_max'][] = $v['viento'];
    }

    return ['latitude' => -29.18, 'longitude' => -58.07, 'daily' => $d];
}

/** Escenario de las fuentes (lo leen los fakes HTTP registrados una vez en beforeEach). Archivo histórico: 3 mm/día salvo los últimos `$secos` días (0,3). */
function fakeClima(array $pronostico, int $secos = 0, int $estadoPronostico = 200): void
{
    app()->instance('test.clima', compact('pronostico', 'secos', 'estadoPronostico'));
}

function registrarFakesClima(): void
{
    Http::fake([
        'archive-api.open-meteo.com/*' => function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $fin = Carbon::parse($q['end_date']);
            $time = [];
            $p = [];
            for ($d = Carbon::parse($q['start_date']); $d->lte($fin); $d->addDay()) {
                $time[] = $d->toDateString();
                $p[] = $d->diffInDays($fin) < app('test.clima')['secos'] ? 0.3 : 3.0;
            }

            return Http::response(['daily' => ['time' => $time, 'precipitation_sum' => $p]]);
        },
        'api.open-meteo.com/v1/forecast*' => fn () => app('test.clima')['estadoPronostico'] === 200
            ? Http::response(app('test.clima')['pronostico']) : Http::response(['error' => true], app('test.clima')['estadoPronostico']),
        'www.cpc.ncep.noaa.gov/*' => Http::response("SEAS  YR   TOTAL   ANOM\n  JJA 2026   26.51   -0.31\n  JAS 2026   26.22   -0.72\n"),
    ]);
}

function avisosClima(string $usuarioId): array
{
    return DB::table('notificaciones')->where('usuario_id', $usuarioId)->where('tipo', 'clima_alerta')->orderBy('titulo')->pluck('titulo')->all();
}

beforeEach(function () {
    Cache::flush();
    registrarFakesClima();
    $this->travelTo('2026-10-20 10:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->enc = miembro($this->est, 'encargado');
    $this->lectura = miembro($this->est, 'lectura');
});

it('calcula alertas con fuente, zona, horizonte y umbral; avisa in-app sin duplicar y sin avisar la señal ENSO', function () {
    fakeClima(pronosticoOpenMeteo([
        1 => ['lluvia' => 95, 'prob' => 90],
        2 => ['tmin' => -2.4],
        3 => ['tmax' => 36, 'hr' => 35], 4 => ['tmax' => 37, 'hr' => 25, 'viento' => 40], 5 => ['tmax' => 36, 'hr' => 35],
    ]));

    $r = $this->getJson("{$this->base}/clima")->assertOk()
        ->assertJsonPath('data.precision', 'departamento')
        ->assertJsonPath('data.zona', 'Depto. Mercedes, Corrientes')
        ->assertJsonPath('data.enso.fase', 'la_nina')
        ->assertJsonPath('data.errores', [])
        ->assertJsonCount(16, 'data.pronostico');
    $alertas = collect($r->json('data.alertas'))->keyBy('regla');
    expect($alertas->keys()->sort()->values()->all())->toBe(['calor_estres', 'enso_fase', 'helada', 'incendio', 'lluvia_extrema'])
        ->and($alertas['lluvia_extrema'])->toMatchArray(['tipo' => 'lluvia', 'severidad' => 'alta', 'valor' => '95', 'umbral' => '80', 'unidad' => 'mm',
            'desde' => '2026-10-21', 'hasta' => '2026-10-21', 'horizonte_dias' => 7, 'fuente' => 'Open-Meteo — pronóstico', 'zona' => 'Depto. Mercedes, Corrientes'])
        ->and($alertas['lluvia_extrema']['detalle'])->toContain('no una certeza')
        ->and($alertas['lluvia_extrema']['recomendacion'])->toStartWith('Para revisar')
        ->and($alertas['helada'])->toMatchArray(['valor' => '-2.4', 'umbral' => '0', 'desde' => '2026-10-22'])
        ->and($alertas['calor_estres'])->toMatchArray(['desde' => '2026-10-23', 'hasta' => '2026-10-25', 'unidad' => 'THI'])
        ->and($alertas['incendio']['desde'])->toBe('2026-10-24')
        ->and($alertas['enso_fase'])->toMatchArray(['severidad' => 'info', 'valor' => '-0.72', 'fuente' => 'NOAA CPC — índice ONI (ENSO)']);
    // Ubicación: centroide del departamento redondeado; nunca se devuelve.
    Http::assertSent(fn (HttpRequest $q) => str_contains($q->url(), 'api.open-meteo.com') && $q['latitude'] == -29.18 && $q['longitude'] == -58.08);
    expect($r->getContent())->not->toContain('-29.18');

    expect(avisosClima($this->dueno->id))->toHaveCount(4)
        ->and(avisosClima($this->enc->id))->toHaveCount(4)
        ->and(avisosClima($this->lectura->id))->toBe([]);

    // Job programado: mismo pronóstico → actualiza las mismas alertas, no avisa de nuevo.
    $this->artisan('clima:actualizar')->assertSuccessful();
    expect(avisosClima($this->dueno->id))->toHaveCount(4)
        ->and(DB::table('clima_alertas')->where('establecimiento_id', $this->est->id)->count())->toBe(5);
});

it('sequía por déficit acumulado; descarta (no borra) lo que el pronóstico ya no sostiene; push si hay dispositivo', function () {
    DB::table('dispositivos')->insert(['id' => (string) Str::uuid7(), 'usuario_id' => $this->enc->id, 'plataforma' => 'android', 'fcm_token' => 'tok', 'created_at' => now(), 'updated_at' => now()]);
    fakeClima(pronosticoOpenMeteo([1 => ['lluvia' => 95]]));
    $this->artisan('clima:actualizar')->assertSuccessful();
    expect(DB::table('clima_alertas')->where('regla_codigo', 'lluvia_extrema')->value('estado'))->toBe('activa');

    Cache::flush();
    fakeClima(pronosticoOpenMeteo(), secos: 60);
    $this->artisan('clima:actualizar')->assertSuccessful();
    expect(DB::table('clima_alertas')->where('regla_codigo', 'lluvia_extrema')->value('estado'))->toBe('descartada');

    $r = $this->getJson("{$this->base}/clima")->assertOk()->assertJsonPath('data.lluvia.ventana_dias', 60)->assertJsonPath('data.lluvia.anios_referencia', 10);
    $sequia = collect($r->json('data.alertas'))->firstWhere('regla', 'sequia_deficit');
    expect($sequia)->toMatchArray(['tipo' => 'sequia', 'unidad' => '%', 'umbral' => '60', 'valor' => '10', 'fuente' => 'Open-Meteo — histórico (reanálisis ERA5)'])
        ->and($sequia['detalle'])->toContain('reanálisis')
        ->and(collect($r->json('data.alertas'))->pluck('regla'))->not->toContain('lluvia_extrema');
    expect(collect(app(PushDriver::class)->enviados)->pluck('token')->unique()->all())->toBe(['tok']);
});

it('si una fuente falla informa el error y conserva las alertas previas', function () {
    fakeClima(pronosticoOpenMeteo([1 => ['lluvia' => 95]]));
    $this->getJson("{$this->base}/clima")->assertOk();

    Cache::flush();
    fakeClima([], estadoPronostico: 500);
    $this->travel(7)->hours();
    $r = $this->getJson("{$this->base}/clima")->assertOk()->assertJsonPath('data.errores.0.fuente', 'open_meteo_forecast');
    expect(collect($r->json('data.alertas'))->pluck('regla'))->toContain('lluvia_extrema')
        ->and($r->json('data.pronostico'))->not->toBe([]);
});

it('usa la ubicación del establecimiento redondeada si existe', function () {
    $this->est->update(['ubicacion' => ['lat' => '-28.123456', 'lon' => '-57.987654']]);
    fakeClima(pronosticoOpenMeteo());
    $this->getJson("{$this->base}/clima")->assertOk()->assertJsonPath('data.precision', 'establecimiento')
        ->assertJsonPath('data.alertas.0.regla', 'enso_fase');
    Http::assertSent(fn (HttpRequest $q) => str_contains($q->url(), 'api.open-meteo.com/v1/forecast') && $q['latitude'] == -28.12 && $q['longitude'] == -57.99);
});

it('reglas configurables por establecimiento: override de umbral, 403 por rol, 422 y 404 ajeno', function () {
    fakeClima(pronosticoOpenMeteo([1 => ['lluvia' => 95]]));
    $this->getJson("{$this->base}/clima/reglas")->assertOk()->assertJsonCount(6, 'data');

    $this->putJson("{$this->base}/clima/reglas/lluvia_extrema", ['parametros' => ['mm_dia' => 120, 'mm_72h' => 150]])->assertOk()
        ->assertJsonPath('data.parametros.mm_dia', 120)->assertJsonPath('data.parametros.horizonte_dias', 7)->assertJsonPath('data.personalizada', true);
    $this->getJson("{$this->base}/clima")->assertOk();
    expect(DB::table('clima_alertas')->where('regla_codigo', 'lluvia_extrema')->where('estado', 'activa')->exists())->toBeFalse();
    // La regla global no cambia para otros establecimientos.
    expect(json_decode(DB::table('clima_reglas')->where('codigo', 'lluvia_extrema')->whereNull('establecimiento_id')->value('parametros'), true)['mm_dia'])->toBe(80);

    $this->putJson("{$this->base}/clima/reglas/helada", ['activa' => false])->assertOk()->assertJsonPath('data.activa', false);
    $this->putJson("{$this->base}/clima/reglas/helada", ['parametros' => ['foo' => 1]])->assertStatus(422);
    $this->putJson("{$this->base}/clima/reglas/helada", ['parametros' => ['tmin_c' => 'frío']])->assertStatus(422);
    $this->putJson("{$this->base}/clima/reglas/inexistente", ['activa' => false])->assertNotFound();

    Sanctum::actingAs($this->lectura);
    $this->getJson("{$this->base}/clima")->assertOk();
    $this->putJson("{$this->base}/clima/reglas/helada", ['activa' => true])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/clima")->assertNotFound();
});

it('THI: índice temperatura-humedad', function () {
    expect(EvaluadorAlertas::thi(35, 40))->toBe(82.8)->and(EvaluadorAlertas::thi(25, 60))->toBe(72.8);
});
