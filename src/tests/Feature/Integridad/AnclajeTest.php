<?php

use App\Domain\Integridad\Anclaje\AnchorDriver;
use App\Domain\Integridad\Anclaje\OpenTimestampsDriver;
use App\Domain\Integridad\Anclaje\Ots;
use App\Domain\Integridad\Anclaje\StubDriver;
use App\Domain\Integridad\Merkle;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
});

function cargarEventos(object $t, int $n): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = $t->postJson("{$t->base}/eventos", altaBody([animalNuevo()], '2026-03-0'.($i % 9 + 1)))->assertCreated()->json('data.id');
    }

    return $ids;
}

it('stub: Merkle de los registros nuevos, prueba por registro verificable, confianza «integridad registrada» y aviso por sync', function () {
    $ids = cargarEventos($this, 5);
    $this->artisan('integridad:anclar')->assertSuccessful();

    $a = DB::table('anclajes')->sole();
    $registros = DB::table('registros_integridad')->orderBy('id')->get();
    expect($a->estado)->toBe('confirmado')->and($a->driver)->toBe('stub')->and($a->hojas)->toBe($registros->count())
        ->and($a->raiz)->toBe(Merkle::raiz($registros->pluck('eslabon')->all()));
    foreach (DB::table('anclaje_inclusiones as i')->join('registros_integridad as r', 'r.id', '=', 'i.registro_id')->get(['i.prueba', 'r.eslabon']) as $inc) {
        expect(Merkle::verificar($inc->eslabon, json_decode($inc->prueba, true), $a->raiz))->toBeTrue();
    }

    $this->getJson("{$this->base}/eventos/{$ids[2]}/integridad")->assertOk()->assertJsonPath('data.confianza', 'integridad_registrada')
        ->assertJsonPath('data.anclaje.estado', 'confirmado')->assertJsonPath('data.anclaje.raiz', $a->raiz)
        ->assertJsonPath('data.anclaje.referencia.stub', true)->assertJson(fn ($j) => $j->whereType('data.prueba', 'array')->etc());
    $huella = DB::table('eventos')->where('id', $ids[2])->value('hash');
    $this->getJson("/api/v1/public/verificaciones/{$huella}")->assertJsonPath('data.confianza', 'integridad_registrada');
    $p = $this->getJson("/api/v1/public/verificaciones/{$huella}/prueba")->assertOk()->json();
    expect(Merkle::verificar($p['eslabon'], $p['prueba_merkle'], $p['anclaje']['raiz']))->toBeTrue();
    $this->get($p['anclaje']['recibo_url'])->assertOk()->assertHeader('Content-Type', 'application/octet-stream');

    $cambio = collect($this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->json('data.cambios'))->firstWhere('entidad', 'anclaje');
    expect($cambio['datos']['estado'])->toBe('confirmado')->and($cambio['datos']['evento_ids'])->toContain($ids[0], $ids[4]);

    // Solo lo nuevo entra en el próximo anclaje; append-only.
    $this->artisan('integridad:anclar')->expectsOutput('Nada para anclar.');
    cargarEventos($this, 1);
    $this->artisan('integridad:anclar')->assertSuccessful();
    expect(DB::table('anclajes')->orderByDesc('id')->first()->hojas)->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('anclaje_inclusiones')->update(['indice_hoja' => 9])))->toThrow(Exception::class);
});

it('si el sello falla 5 veces queda fallido y esos registros se re-anclan en un anclaje nuevo', function () {
    cargarEventos($this, 2);
    app()->instance(AnchorDriver::class, new class implements AnchorDriver
    {
        public function nombre(): string
        {
            return 'opentimestamps';
        }

        public function sellar(string $r): never
        {
            throw new RuntimeException('calendarios caídos');
        }

        public function actualizar(string $r, string $x): never
        {
            throw new RuntimeException('no');
        }
    });
    $this->artisan('integridad:anclar');
    for ($i = 0; $i < 4; $i++) {
        $this->artisan('integridad:actualizar-anclajes');
    }
    $fallido = DB::table('anclajes')->sole();
    expect($fallido->estado)->toBe('fallido')->and($fallido->intentos)->toBe(5)->and($fallido->error)->toBe('calendarios caídos');
    $this->getJson("{$this->base}/eventos")->assertOk();

    app()->instance(AnchorDriver::class, new StubDriver);
    $this->artisan('integridad:anclar');
    $nuevo = DB::table('anclajes')->where('estado', 'confirmado')->sole();
    expect($nuevo->hojas)->toBe($fallido->hojas)->and(DB::table('anclaje_inclusiones')->count())->toBe(2 * $fallido->hojas);
});

it('OpenTimestamps: sella en 2 calendarios, arma el .ots y confirma cuando el calendario devuelve la atestación de Bitcoin', function () {
    cargarEventos($this, 3);
    $sal = random_bytes(16);
    $pendiente = fn (string $cal) => "\xf0".Ots::varbytes($sal)."\x08\x00".Ots::TAG_PENDIENTE.Ots::varbytes(Ots::varbytes($cal));
    $compromiso = null;
    $minado = false; // Bitcoin tarda horas: al principio el calendario no tiene la atestación
    Http::fake(function (HttpRequest $r) use ($pendiente, $sal, &$compromiso, &$minado) {
        if (str_ends_with($r->url(), '/digest')) {
            expect(strlen($r->body()))->toBe(32);
            $compromiso = hash('sha256', $r->body().$sal);

            return Http::response($pendiente(preg_replace('#/digest$#', '', $r->url())), 200);
        }
        if (str_contains($r->url(), '/timestamp/')) {
            expect(basename($r->url()))->toBe($compromiso); // el commitment se calcula aplicando append + sha256

            return $minado && str_starts_with($r->url(), 'https://a.cal')
                ? Http::response("\x08\x00".Ots::TAG_BITCOIN.Ots::varbytes(Ots::varuint(860000)), 200)
                : Http::response('', 404);
        }

        return Http::response('', 500);
    });
    app()->instance(AnchorDriver::class, new OpenTimestampsDriver(['https://a.cal', 'https://b.cal'], null));

    $this->artisan('integridad:anclar');
    $a = DB::table('anclajes')->sole();
    expect($a->estado)->toBe('enviado')->and($a->red)->toBe('bitcoin');
    $ots = Ots::leerArchivo(Storage::get($a->recibo_clave));
    expect(bin2hex($ots['digest']))->toBe($a->raiz)->and(Ots::pendientes($ots['nodo']))->toHaveCount(2);

    $minado = true;
    $this->artisan('integridad:actualizar-anclajes')->assertSuccessful();
    $a = DB::table('anclajes')->sole();
    expect($a->estado)->toBe('confirmado')->and(json_decode($a->referencia, true)['bloque'])->toBe(860000)
        ->and($a->recibo_clave)->toBe("anclajes/{$a->id}.v2.ots")->and(Storage::exists("anclajes/{$a->id}.ots"))->toBeTrue()
        ->and(Ots::alturaBitcoin(Ots::leerArchivo(Storage::get($a->recibo_clave))['nodo']))->toBe(860000);
});

it('con el driver stub en producción la app no arranca', function () {
    $this->app['env'] = 'production';
    config(['tropilla.anclaje.driver' => 'stub']);
    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);
    $this->app['env'] = 'testing';
});
