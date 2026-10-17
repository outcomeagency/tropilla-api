<?php

use App\Domain\Clima\Acciones\GenerarEscenariosClimaticos;
use App\Domain\Clima\Jobs\CalcularEscenariosClimaticos;
use App\Domain\Documentos\Borradores\Extraccion\FakeExtractor;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/clima/escenarios";
});

it('encola el primer cálculo y no consulta fuentes externas dentro de la petición', function () {
    Queue::fake();
    Sanctum::actingAs(duenoDe($this->est));

    $this->getJson($this->url)->assertStatus(202)->assertJsonPath('meta.estado', 'calculando');
    Queue::assertPushed(CalcularEscenariosClimaticos::class, fn ($job) => $job->establecimientoId === $this->est->id);
});

it('exige sesión y restringe por membresía del establecimiento', function () {
    $this->getJson($this->url)->assertUnauthorized();

    Sanctum::actingAs(duenoDe(crearEstablecimiento(extra: ['nombre' => 'Ajeno'])));
    $this->getJson($this->url)->assertNotFound();
});

it('combina señales de NASA, MODIS y NOAA y usa el resumen IA falso en pruebas', function () {
    Cache::flush();
    FakeExtractor::reset();
    FakeExtractor::$salida = ['resumen' => 'Resumen redactado con el fake.', 'plan' => 'Plan prudente redactado con el fake.'];
    Http::fake([
        'power.larc.nasa.gov/*' => Http::response(['properties' => ['parameter' => ['PRECTOTCORR' => ['20261001' => 1.0, '20261002' => 0.5]]]]),
        'modis.ornl.gov/*/dates*' => Http::response(['dates' => [['modis_date' => 'A2026280'], ['modis_date' => 'A2026290']]]),
        'modis.ornl.gov/*/subset*' => Http::response(['subset' => [['calendar_date' => '2026-10-17', 'band' => '250m_16_days_NDVI', 'data' => [4210]]]]),
        'www.cpc.ncep.noaa.gov/*' => Http::response('<table><tr><td>SON</td><td>Sep</td><td>Oct</td><td>Nov</td><td>0</td><td>0</td><td>100</td></tr></table>'),
    ]);

    $salida = app(GenerarEscenariosClimaticos::class)->handle($this->est);

    expect($salida['escenarios'])->toHaveCount(4)
        ->and($salida['resumen'])->toBe('Resumen redactado con el fake.')
        ->and($salida['plan_respuesta'][0]['orientacion'])->toBe('Plan prudente redactado con el fake.')
        ->and($salida['proveedor_ia'])->toBe('fake')
        ->and($salida['fuentes']['noaa_enso']['periodos'][0]['probabilidades']['el_nino_pct'])->toBe(100)
        ->and($salida['escenarios'][2]['descripcion'])->toContain('NDVI 0,421')
        ->and($salida['escenarios'][0]['probabilidad'])->toBeNull()
        ->and(DB::table('escenarios_climaticos')->where('establecimiento_id', $this->est->id)->count())->toBe(1);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'power.larc.nasa.gov'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'modis.ornl.gov'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'cpc.ncep.noaa.gov'));
});

it('lee la tabla oficial de NOAA CPC y marca el exceso hídrico con El Niño probable', function () {
    Cache::flush();
    FakeExtractor::reset();
    Http::fake([
        'power.larc.nasa.gov/*' => Http::response(['properties' => ['parameter' => ['PRECTOTCORR' => ['20261001' => 4.0]]]]),
        'modis.ornl.gov/*' => Http::response([], 500),
        'www.cpc.ncep.noaa.gov/*' => Http::response('<table id="probabilities-table"><tbody><tr class="cell-headings"><th scope="col">Season</th><th scope="col">La Niña</th><th scope="col">Neutral</th><th scope="col">El Niño</th></tr>'
            .'<tr><th scope="row"><abbr>SON <span class="tooltip">Sep Oct Nov</span></abbr></th><td>0</td><td>0</td><td>100</td></tr>'
            .'<tr><th scope="row"><abbr>OND <span class="tooltip">Oct Nov Dec</span></abbr></th><td>0</td><td>2</td><td>98</td></tr></tbody></table>'),
    ]);

    $salida = app(GenerarEscenariosClimaticos::class)->handle($this->est);
    $porCodigo = collect($salida['escenarios'])->keyBy('codigo');

    expect($salida['fuentes']['noaa_enso']['periodos'])->toHaveCount(2)
        ->and($salida['fuentes']['noaa_enso']['periodos'][1])->toBe(['temporada' => 'OND', 'probabilidades' => ['la_nina_pct' => 0, 'neutral_pct' => 2, 'el_nino_pct' => 98]])
        ->and($porCodigo['exceso_hidrico']['nivel'])->toBe('amarillo')
        ->and($porCodigo['exceso_hidrico']['descripcion'])->toContain('100 % a El Niño para SON')
        ->and($porCodigo['sequia']['nivel'])->toBe('gris')
        ->and($porCodigo['exceso_hidrico']['probabilidad'])->toBeNull();
});
