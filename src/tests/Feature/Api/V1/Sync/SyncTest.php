<?php

use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->user = miembro($this->est, 'encargado');
    $this->disp = (string) Str::uuid7();
    DB::table('dispositivos')->insert(['id' => $this->disp, 'usuario_id' => $this->user->id, 'plataforma' => 'android', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($this->user, ['app']);
});

/** Sobre `tropilla.evento/1` como lo arma el teléfono. */
function sobreApp(object $t, string $tipo, array $sujetos, array $payload, array $extra = []): stdClass
{
    $id = $extra['id'] ?? (string) Str::uuid7();

    return json_decode(json_encode(array_filter([
        'esquema' => 'tropilla.evento/1', 'id' => $id, 'establecimiento_id' => $t->est->id,
        'evento_raiz_id' => $extra['evento_raiz_id'] ?? $id, 'version' => $extra['version'] ?? 1, 'accion' => $extra['accion'] ?? 'alta',
        'corrige_evento_id' => $extra['corrige_evento_id'] ?? null, 'hash_anterior' => $extra['hash_anterior'] ?? null, 'motivo' => $extra['motivo'] ?? null,
        'tipo' => $tipo, 'tipo_version' => 1, 'sujetos' => $sujetos, 'payload' => $payload ?: new stdClass,
        'ocurrido_en' => $extra['ocurrido_en'] ?? '2026-10-15', 'creado_en' => $extra['creado_en'] ?? '2026-10-15T12:31:07.250Z',
        'autor_id' => $t->user->id, 'dispositivo_id' => $t->disp, 'origen' => 'app',
    ], fn ($v) => $v !== null)));
}

function item(stdClass $sobre): array
{
    return ['clase' => 'evento', 'sobre' => $sobre, 'huella' => CanonicalJson::huella($sobre)];
}

function push(object $t, array $items)
{
    return $t->postJson('/api/v1/sync/push', ['establecimiento_id' => $t->est->id, 'dispositivo_id' => $t->disp, 'envio_id' => (string) Str::uuid7(), 'items' => $items]);
}

it('push idempotente: el mismo lote dos veces → aceptado y después duplicado, una fila por id, seq sin huecos', function () {
    $a = animalNuevo();
    $alta = sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]]);
    $pesaje = sobreApp($this, 'hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['modo' => 'individual', 'peso_kg' => '412.5', 'desbaste' => false, 'metodo' => 'balanza']);

    $r1 = push($this, [item($alta), item($pesaje)])->assertOk()->json('resultados');
    expect(array_column($r1, 'estado'))->toBe(['aceptado', 'aceptado'])->and(array_column($r1, 'seq'))->toBe([1, 2]);
    $r2 = push($this, [item($alta), item($pesaje)])->assertOk()->json('resultados');
    expect(array_column($r2, 'estado'))->toBe(['duplicado', 'duplicado'])->and(array_column($r2, 'seq'))->toBe([1, 2]);

    expect(DB::table('eventos')->count())->toBe(2)
        ->and(DB::table('registros_integridad')->orderBy('seq')->pluck('seq')->all())->toBe([1, 2])
        ->and(DB::table('animales_estado')->value('peso_kg'))->toBe('412.5')
        ->and(DB::table('eventos')->where('id', $pesaje->id)->value('origen'))->toBe('app');
});

it('rechazos por ítem: huella inválida, float, mismo id con otra huella, dependencia faltante (reintentable)', function () {
    $a = animalNuevo();
    $alta = sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]]);
    $malHuella = ['clase' => 'evento', 'sobre' => $alta, 'huella' => str_repeat('a', 64)];
    $pesajeHuerfano = sobreApp($this, 'hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [(string) Str::uuid7()]], ['modo' => 'individual', 'peso_kg' => '400', 'metodo' => 'balanza']);

    $raw = '{"establecimiento_id":"'.$this->est->id.'","dispositivo_id":"'.$this->disp.'","items":[{"clase":"evento","huella":"'.str_repeat('b', 64).'","sobre":{"esquema":"tropilla.evento/1","payload":{"peso_kg":412.5}}}]}';
    $float = $this->call('POST', '/api/v1/sync/push', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertOk()->json('resultados.0');
    expect($float['codigo'])->toBe('canonicalizacion')->and($float['reintentable'])->toBeFalse();

    $r = push($this, [$malHuella, item($alta), item($pesajeHuerfano)])->assertOk()->json('resultados');
    expect($r[0])->toMatchArray(['estado' => 'rechazado', 'codigo' => 'huella_invalida', 'reintentable' => false])
        ->and($r[1]['estado'])->toBe('aceptado')
        ->and($r[2])->toMatchArray(['estado' => 'rechazado', 'codigo' => 'dependencia_faltante', 'reintentable' => true]);

    $otro = clone $alta;
    $otro->observaciones = 'cambiado';
    expect(push($this, [item($otro)])->json('resultados.0.codigo'))->toBe('id_reutilizado');
});

it('dos correcciones de la misma versión: una vigente y la otra en revisión (gana el servidor)', function () {
    $a = animalNuevo();
    $alta = sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]]);
    $p1 = sobreApp($this, 'hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['modo' => 'individual', 'peso_kg' => '412.5', 'metodo' => 'balanza']);
    push($this, [item($alta), item($p1)])->assertOk();
    $base = ['evento_raiz_id' => $p1->id, 'version' => 2, 'accion' => 'correccion', 'corrige_evento_id' => $p1->id, 'hash_anterior' => CanonicalJson::huella($p1)];
    $c1 = sobreApp($this, 'hacienda.pesaje', (array) $p1->sujetos, ['modo' => 'individual', 'peso_kg' => '421.5', 'metodo' => 'balanza'], [...$base, 'motivo' => 'Teléfono A']);
    $c2 = sobreApp($this, 'hacienda.pesaje', (array) $p1->sujetos, ['modo' => 'individual', 'peso_kg' => '430', 'metodo' => 'balanza'], [...$base, 'motivo' => 'Teléfono B']);

    $r = push($this, [item($c1), item($c2)])->assertOk()->json('resultados');
    expect($r[0]['estado'])->toBe('aceptado')
        ->and($r[1])->toMatchArray(['estado' => 'en_revision', 'codigo' => 'conflicto_version'])
        ->and($r[1]['version_vigente']['id'])->toBe($c1->id)
        ->and(DB::table('animales_estado')->value('peso_kg'))->toBe('421.5')
        ->and(DB::table('revisiones')->where('tipo', 'correccion_en_conflicto')->count())->toBe(1);

    // resolver reaplicando desde la web
    Sanctum::actingAs(duenoDe($this->est));
    $rev = DB::table('revisiones')->value('id');
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/revisiones/{$rev}/resolver", [
        'resolucion' => 'reaplicada', 'motivo' => 'Vale el de B', 'correccion' => ['motivo' => 'Peso de B', 'payload' => ['modo' => 'individual', 'peso_kg' => '430', 'metodo' => 'balanza']],
    ])->assertOk()->assertJsonPath('data.estado', 'reaplicada');
    expect(DB::table('animales_estado')->value('peso_kg'))->toBe('430.0');
});

it('push por sync acepta un evento posterior a la baja con observación y revisión', function () {
    $a = animalNuevo();
    $alta = sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]], ['ocurrido_en' => '2026-01-01']);
    $muerte = sobreApp($this, 'hacienda.muerte', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['causa' => 'rayo'], ['ocurrido_en' => '2026-05-01']);
    $pesaje = sobreApp($this, 'hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['modo' => 'individual', 'peso_kg' => '400', 'metodo' => 'balanza'], ['ocurrido_en' => '2026-05-10']);

    $r = push($this, [item($alta), item($muerte), item($pesaje)])->json('resultados');
    expect($r[2]['estado'])->toBe('aceptado')->and($r[2]['observaciones'])->toBe(['evento_posterior_a_baja'])
        ->and(DB::table('revisiones')->where('tipo', 'evento_posterior_a_baja')->count())->toBe(1);
});

it('maestros: alta offline de potrero/lote (base 0), edición con row_version y conflicto → en revisión', function () {
    $pid = (string) Str::uuid7();
    $lid = (string) Str::uuid7();
    $r = push($this, [
        ['clase' => 'maestro', 'entidad' => 'potrero', 'id' => $pid, 'base_row_version' => 0, 'datos' => ['nombre' => 'Isla Grande', 'ambiente' => 'isla']],
        ['clase' => 'maestro', 'entidad' => 'lote', 'id' => $lid, 'base_row_version' => 0, 'datos' => ['nombre' => 'Recría 2026', 'especie' => 'BUB', 'anonimo' => true]],
        ['clase' => 'maestro', 'entidad' => 'potrero', 'id' => $pid, 'base_row_version' => 1, 'datos' => ['notas' => 'Se inunda en otoño']],
        ['clase' => 'maestro', 'entidad' => 'potrero', 'id' => $pid, 'base_row_version' => 1, 'datos' => ['notas' => 'Otra nota']],
    ])->assertOk()->json('resultados');

    expect(array_column($r, 'estado'))->toBe(['aceptado', 'aceptado', 'aceptado', 'en_revision'])
        ->and($r[3]['row_version'])->toBe(2)
        ->and(DB::table('potreros')->where('id', $pid)->value('notas'))->toBe('Se inunda en otoño')
        ->and(DB::table('revisiones')->where('tipo', 'maestro_en_conflicto')->count())->toBe(1);
});

it('pull: cambios desde el cursor, hidratados y deduplicados; cursor de otro establecimiento → 422', function () {
    $a = animalNuevo();
    push($this, [item(sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]]))]);

    $p = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->assertOk()->assertJsonPath('hay_mas', false);
    $entidades = collect($p->json('cambios'))->pluck('entidad')->all();
    expect($entidades)->toContain('establecimiento', 'membresia', 'evento', 'animal')
        ->and(collect($p->json('cambios'))->firstWhere('entidad', 'animal')['datos']['id'])->toBe($a['animal_id']);

    $p2 = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}&cursor={$p->json('cursor')}")->assertOk();
    expect($p2->json('cambios'))->toBe([]);

    $chico = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}&limite=2")->assertJsonPath('hay_mas', true);
    $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}&cursor={$chico->json('cursor')}")->assertOk();

    $otro = crearEstablecimiento();
    $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}&cursor=".rtrim(strtr(base64_encode("v1:{$otro->id}:1"), '+/', '-_'), '='))
        ->assertStatus(422)->assertJsonPath('code', 'cursor_invalido');
});

it('el encargado no recibe cambios comerciales ni fiscales por pull', function () {
    DB::table('cadenas')->where('establecimiento_id', $this->est->id)->increment('ultimo_seq_cambios');
    $seq = DB::table('cadenas')->where('establecimiento_id', $this->est->id)->value('ultimo_seq_cambios');
    DB::table('cambios')->insert(['establecimiento_id' => $this->est->id, 'seq' => $seq, 'entidad' => 'documento', 'entidad_id' => (string) Str::uuid7(), 'op' => 'upsert', 'visibilidad' => 'fiscal', 'created_at' => now()]);

    $cambios = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->json('cambios');
    expect(collect($cambios)->pluck('entidad')->all())->not->toContain('documento');
});

it('snapshot: estado completo + cursor para seguir con pull', function () {
    $a = animalNuevo();
    push($this, [item(sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]], ['ocurrido_en' => now()->toDateString()]))]);

    $s = $this->getJson("/api/v1/sync/snapshot?establecimiento_id={$this->est->id}")->assertOk()
        ->assertJsonPath('establecimiento.id', $this->est->id)->assertJsonCount(1, 'animales')->assertJsonCount(1, 'eventos')
        ->assertJsonStructure(['potreros', 'lotes', 'usuarios', 'cursor', 'catalogos_version', 'generado_en']);
    $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}&cursor={$s->json('cursor')}")->assertOk()->assertJsonPath('cambios', []);
});

it('lectura no puede pushear eventos; otro establecimiento 404; membresía revocada: 403 en pull y ventana de 72 h en push', function () {
    $a = animalNuevo();
    $alta = sobreApp($this, 'hacienda.alta', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], ['motivo' => 'alta_rapida', 'animales' => [$a]],
        ['creado_en' => now()->subHour()->utc()->format('Y-m-d\TH:i:s.v\Z')]);

    Membresia::query()->where('usuario_id', $this->user->id)->update(['estado' => 'revocada', 'revocada_en' => now()]);
    $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->assertForbidden()->assertJsonPath('code', 'membresia_revocada');
    $r = push($this, [item($alta)])->assertOk()->json('resultados.0');
    expect($r['estado'])->toBe('aceptado')->and($r['observaciones'])->toContain('carga_post_revocacion');

    $this->travel(73)->hours();
    push($this, [item($alta)])->assertForbidden()->assertJsonPath('code', 'establecimiento_cerrado');
    $this->travelBack();

    $otro = crearEstablecimiento();
    $this->postJson('/api/v1/sync/push', ['establecimiento_id' => $otro->id, 'dispositivo_id' => $this->disp, 'items' => []])->assertNotFound();

    $lector = miembro($this->est, 'lectura');
    DB::table('dispositivos')->insert(['id' => $d = (string) Str::uuid7(), 'usuario_id' => $lector->id, 'plataforma' => 'ios', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($lector, ['app']);
    $this->postJson('/api/v1/sync/push', ['establecimiento_id' => $this->est->id, 'dispositivo_id' => $d, 'items' => []])->assertForbidden();
});

it('422 si el envío no trae establecimiento, dispositivo o items válidos', function () {
    $this->postJson('/api/v1/sync/push', ['items' => array_fill(0, 201, [])])->assertStatus(422)->assertJsonValidationErrors(['establecimiento_id', 'dispositivo_id', 'items']);
});
