<?php

use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\Models\Cadena;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function cadenaVector(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../fixtures/vectores-hash-v1.json'), true)['cadena'];
}

it('encadena en orden de llegada con lock y reproduce los eslabones de los vectores', function () {
    $est = crearEstablecimiento();
    $registrar = app(RegistrarEnCadena::class);

    foreach (cadenaVector() as $c) {
        $r = DB::transaction(fn () => $registrar->handle(Cadena::bloquearDeEstablecimiento($est->id), 'evento', (string) Str::uuid7(), $c['huella']));
        expect($r->seq)->toBe($c['seq'])->and($r->eslabon)->toBe($c['eslabon']);
    }

    $cadena = Cadena::query()->where('establecimiento_id', $est->id)->sole();
    expect($cadena->ultimo_seq)->toBe(5)
        ->and($cadena->ultimo_eslabon)->toBe(cadenaVector()[4]['eslabon']);

    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('cada cadena es independiente (génesis propia por establecimiento)', function () {
    $a = crearEstablecimiento();
    $b = crearEstablecimiento();
    $h = cadenaVector()[0]['huella'];
    $r1 = DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($a->id), 'evento', (string) Str::uuid7(), $h));
    $r2 = DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($b->id), 'evento', (string) Str::uuid7(), $h));

    expect($r1->seq)->toBe(1)->and($r2->seq)->toBe(1)->and($r1->eslabon)->toBe($r2->eslabon);
});

it('un mismo sujeto no se encadena dos veces', function () {
    $est = crearEstablecimiento();
    $id = (string) Str::uuid7();
    DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($est->id), 'evento', $id, cadenaVector()[0]['huella']));
    expect(fn () => DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($est->id), 'evento', $id, cadenaVector()[1]['huella'])))
        ->toThrow(QueryException::class);
});

it('registros_integridad, cambios y auditoría rechazan UPDATE y DELETE (append-only por trigger)', function (string $tabla, string $set) {
    $est = crearEstablecimiento();
    DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($est->id), 'evento', (string) Str::uuid7(), cadenaVector()[0]['huella']));

    expect(fn () => DB::transaction(fn () => DB::update("UPDATE {$tabla} SET {$set}")))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::delete("DELETE FROM {$tabla}")))->toThrow(QueryException::class, 'append-only');
})->with([
    ['registros_integridad', "huella = repeat('a', 64)"],
    ['cambios', "op = 'ocultar'"],
    ['auditoria', "accion = 'x'"],
]);

it('integridad:verificar-cadenas detecta una fila alterada (con el trigger deshabilitado)', function () {
    $est = crearEstablecimiento();
    foreach (array_slice(cadenaVector(), 0, 3) as $c) {
        DB::transaction(fn () => app(RegistrarEnCadena::class)->handle(Cadena::bloquearDeEstablecimiento($est->id), 'evento', (string) Str::uuid7(), $c['huella']));
    }

    DB::statement('ALTER TABLE registros_integridad DISABLE TRIGGER registros_integridad_inmutable');
    DB::update("UPDATE registros_integridad SET huella = repeat('b', 64) WHERE seq = 2");
    DB::statement('ALTER TABLE registros_integridad ENABLE TRIGGER registros_integridad_inmutable');

    $this->artisan('integridad:verificar-cadenas')->assertFailed();
});
