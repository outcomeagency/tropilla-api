<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->pdf = '%PDF-1.4 certificado de vacunación';
    $this->sha = app(GuardarArchivo::class)->handle($this->pdf, 'application/pdf', $this->dueno);
});

function archivoRef(string $sha, int $bytes = 1000, string $mime = 'application/pdf'): array
{
    return ['sha256' => $sha, 'bytes' => $bytes, 'mime' => $mime];
}

function docBody(array $extra = []): array
{
    return array_merge(['tipo' => 'vacunacion_aftosa', 'titulo' => 'Acta aftosa 2026-2', 'emisor' => ['nombre' => 'Fundación Corrientes', 'identificacion' => 'FUCOSA'],
        'emitido_en' => '2026-09-15', 'vence_en' => '2027-03-15'], $extra);
}

it('alta con archivos y vínculos → versiones inmutables encadenadas: nueva versión, corrección de metadatos y revocación', function () {
    $a = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a], '2026-01-10'))->assertCreated();
    $pendiente = hash('sha256', 'foto que sube después');

    $doc = $this->postJson("{$this->base}/documentos", docBody([
        'vinculos' => [['tipo' => 'animal', 'id' => $a['animal_id']], ['tipo' => 'establecimiento', 'id' => $this->est->id]],
        'archivos' => [archivoRef($this->sha, strlen($this->pdf)), archivoRef($pendiente, 2048, 'image/jpeg')],
    ]))->assertCreated()
        ->assertJsonPath('data.privacidad', 'sanitario')->assertJsonPath('data.estado', 'vigente')->assertJsonPath('data.visibilidad', 'privado')
        ->assertJsonPath('data.version_vigente', 1)->assertJsonPath('data.confianza', 'declarado')->assertJsonCount(2, 'data.vinculos')
        ->assertJsonPath('data.versiones.0.archivos.0.estado', 'completo')->assertJsonPath('data.versiones.0.archivos.1.estado', 'pendiente')
        ->assertJsonPath('data.versiones.0.emisor.nombre', 'Fundación Corrientes')->assertJsonPath('data.versiones.0.autor.rol', 'dueno');
    $id = $doc->json('data.id');
    $h1 = $doc->json('data.versiones.0.huella');
    expect($doc->json('data.verificacion_url'))->toEndWith("/v/{$h1}")
        ->and(DB::table('registros_integridad')->where('sujeto_tipo', 'documento_version')->count())->toBe(1);
    // La huella se recalcula desde el sobre guardado.
    expect(CanonicalJson::huella(CanonicalJson::decode(DB::table('documento_versiones')->where('documento_id', $id)->selectRaw('sobre::text as s')->value('s'))))->toBe($h1);
    $this->getJson("{$this->base}/animales/{$a['animal_id']}/documentos")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

    // Nueva versión: no borra la anterior.
    $sha2 = hash('sha256', 'acta corregida');
    $v2 = $this->postJson("{$this->base}/documentos/{$id}/versiones", ['accion' => 'nueva_version', 'motivo' => 'Faltaba la firma', 'archivos' => [archivoRef($sha2)]])
        ->assertCreated()->assertJsonPath('data.version_vigente', 2)->assertJsonCount(2, 'data.versiones');
    expect(DB::table('documento_versiones')->where('documento_id', $id)->where('version', 2)->value('hash_anterior'))->toBe($h1);
    // Corrección de metadatos: conserva los archivos de la vigente.
    $this->postJson("{$this->base}/documentos/{$id}/versiones", ['accion' => 'correccion_metadatos', 'motivo' => 'Fecha mal cargada', 'vence_en' => '2027-03-31'])
        ->assertCreated()->assertJsonPath('data.vence_en', '2027-03-31')->assertJsonPath('data.versiones.0.archivos.0.sha256', $sha2);
    // Revocación: queda revocado y no admite más versiones.
    $this->postJson("{$this->base}/documentos/{$id}/versiones", ['accion' => 'revocacion', 'motivo' => 'Documento anulado por el emisor'])
        ->assertCreated()->assertJsonPath('data.estado', 'revocado')->assertJsonCount(4, 'data.versiones');
    $this->postJson("{$this->base}/documentos/{$id}/versiones", ['accion' => 'correccion_metadatos', 'motivo' => 'Otra vez', 'titulo' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    expect(fn () => DB::transaction(fn () => DB::table('documento_versiones')->where('documento_id', $id)->update(['titulo' => 'trucho'])))->toThrow(Exception::class);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('fiscal: solo dueño/admin lo cargan y lo ven; nunca publicable; publicar un sanitario lo hace verificable por archivo', function () {
    $fact = $this->postJson("{$this->base}/documentos", docBody(['tipo' => 'factura', 'titulo' => 'Factura flete', 'archivos' => [archivoRef($this->sha)]]))
        ->assertCreated()->assertJsonPath('data.privacidad', 'fiscal');
    $this->postJson("{$this->base}/documentos/{$fact->json('data.id')}/visibilidad", ['visibilidad' => 'perfil', 'row_version' => 1])
        ->assertStatus(422)->assertJsonPath('code', 'no_publicable');
    $this->postJson("{$this->base}/documentos", docBody(['tipo' => 'factura', 'privacidad' => 'general', 'archivos' => [archivoRef($this->sha)]]))
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $san = $this->postJson("{$this->base}/documentos", docBody(['archivos' => [archivoRef($this->sha)]]))->assertCreated();
    $this->getJson("/api/v1/public/verificaciones/archivo/{$this->sha}")->assertJsonPath('data.coincide', false); // privado: no se revela
    $this->postJson("{$this->base}/documentos/{$san->json('data.id')}/visibilidad", ['visibilidad' => 'perfil', 'row_version' => 1])
        ->assertOk()->assertJsonPath('data.visibilidad', 'perfil')->assertJsonPath('data.row_version', 2);
    $this->postJson("{$this->base}/documentos/{$san->json('data.id')}/visibilidad", ['visibilidad' => 'privado', 'row_version' => 1])->assertStatus(409);
    $this->getJson("/api/v1/public/verificaciones/archivo/{$this->sha}")->assertJsonPath('data.coincide', true)->assertJsonPath('data.documento.titulo', 'Acta aftosa 2026-2');
    $this->getJson('/api/v1/public/verificaciones/'.$san->json('data.versiones.0.huella'))->assertOk()->assertJsonPath('data.tipo', 'documento_version');

    // Encargado: no ve ni carga fiscales; versiona solo lo propio.
    Sanctum::actingAs($enc = miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/documentos")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/documentos/{$fact->json('data.id')}")->assertNotFound();
    $this->postJson("{$this->base}/documentos", docBody(['tipo' => 'factura', 'archivos' => [archivoRef($this->sha)]]))->assertForbidden();
    $this->postJson("{$this->base}/documentos/{$san->json('data.id')}/versiones", ['accion' => 'correccion_metadatos', 'motivo' => 'No es mío', 'titulo' => 'X'])->assertForbidden();
    $propio = $this->postJson("{$this->base}/documentos", docBody(['tipo' => 'foto_evidencia', 'titulo' => 'Foto manga', 'archivos' => [archivoRef($this->sha)]]))->assertCreated();
    $this->postJson("{$this->base}/documentos/{$propio->json('data.id')}/versiones", ['accion' => 'correccion_metadatos', 'motivo' => 'Título', 'titulo' => 'Foto manga 2'])->assertCreated();
    $this->postJson("{$this->base}/documentos/{$san->json('data.id')}/visibilidad", ['visibilidad' => 'privado', 'row_version' => 2])->assertForbidden();
});

it('vencimientos 30 días y filtros (estado, tipo, q, vínculo, vence_antes)', function () {
    foreach ([['Rabia', 'vacunacion_rabia', '2026-10-06'], ['Renspa', 'constancia_renspa', '2026-11-20'], ['Vieja', 'certificado_sanitario', '2026-09-01'], ['Sin vencimiento', 'otro', null]] as [$t, $tipo, $vence]) {
        $this->postJson("{$this->base}/documentos", ['tipo' => $tipo, 'titulo' => $t, 'vence_en' => $vence, 'archivos' => [archivoRef($this->sha)]])->assertCreated();
    }
    $v = $this->getJson("{$this->base}/documentos/vencimientos?dias=30")->assertOk()->json('data');
    expect(array_column($v, 'titulo'))->toBe(['Vieja', 'Rabia'])->and(array_column($v, 'estado'))->toBe(['vencido', 'vigente']);
    $this->getJson("{$this->base}/documentos/vencimientos?dias=60")->assertJsonCount(3, 'data');
    $this->getJson("{$this->base}/documentos?estado=vencido")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/documentos?estado=vigente")->assertJsonCount(3, 'data');
    $this->getJson("{$this->base}/documentos?tipo=constancia_renspa")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/documentos?q=renspa")->assertJsonCount(1, 'data');
    $this->getJson("{$this->base}/documentos?vence_antes=2026-10-31")->assertJsonCount(2, 'data');
    $this->getJson("{$this->base}/documentos?vinculo_tipo=establecimiento&vinculo_id={$this->est->id}")->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/documentos?limit=2")->assertJsonCount(2, 'data')->assertJson(fn ($j) => $j->whereNot('meta.next_cursor', null)->etc());
});

it('por sync (app): alta idempotente, conflicto de versión a revisión, viaja por pull y snapshot', function () {
    $user = miembro($this->est, 'encargado');
    $disp = (string) Str::uuid7();
    DB::table('dispositivos')->insert(['id' => $disp, 'usuario_id' => $user->id, 'plataforma' => 'android', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($user, ['app']);
    $docId = (string) Str::uuid7();
    $sobre = fn (array $x) => json_decode(json_encode(array_merge([
        'esquema' => 'tropilla.documento_version/1', 'id' => (string) Str::uuid7(), 'documento_id' => $docId, 'establecimiento_id' => $this->est->id,
        'version' => 1, 'accion' => 'alta', 'tipo' => 'foto_evidencia', 'privacidad' => 'general', 'titulo' => 'Foto del parto',
        'archivos' => [archivoRef(hash('sha256', 'jpg'), 345678, 'image/jpeg')], 'creado_en' => '2026-10-01T10:00:00.000Z', 'autor_id' => $user->id, 'dispositivo_id' => $disp,
    ], $x)));
    $push = fn (array $sobres) => $this->postJson('/api/v1/sync/push', ['establecimiento_id' => $this->est->id, 'dispositivo_id' => $disp, 'envio_id' => (string) Str::uuid7(),
        'items' => array_map(fn ($s) => ['clase' => 'documento_version', 'sobre' => $s, 'huella' => CanonicalJson::huella($s)], $sobres)])->assertOk()->json('data.resultados');

    $v1 = $sobre([]);
    expect($push([$v1])[0]['estado'])->toBe('aceptado')->and($push([$v1])[0]['estado'])->toBe('duplicado');
    $h1 = CanonicalJson::huella($v1);
    $a = $sobre(['version' => 2, 'accion' => 'correccion_metadatos', 'hash_anterior' => $h1, 'motivo' => 'Teléfono A', 'titulo' => 'Parto U-0142']);
    $b = $sobre(['version' => 2, 'accion' => 'correccion_metadatos', 'hash_anterior' => $h1, 'motivo' => 'Teléfono B', 'titulo' => 'Parto 0142']);
    $r = $push([$a, $b]);
    expect($r[0]['estado'])->toBe('aceptado')->and($r[1]['estado'])->toBe('en_revision')->and($r[1]['codigo'])->toBe('conflicto_version')
        ->and(DB::table('documentos')->where('id', $docId)->value('titulo'))->toBe('Parto U-0142');
    expect($push([$sobre(['tipo' => 'no_existe'])])[0]['codigo'])->toBe('esquema_invalido');
    expect($push([$sobre(['documento_id' => (string) Str::uuid7(), 'vinculos' => [['tipo' => 'animal', 'id' => (string) Str::uuid7()]]])])[0])
        ->toMatchArray(['codigo' => 'dependencia_faltante', 'reintentable' => true]);

    $cambios = collect($this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->json('data.cambios'));
    expect($cambios->firstWhere('entidad', 'documento')['datos']['titulo'])->toBe('Parto U-0142');
    $snap = $this->getJson("/api/v1/sync/snapshot?establecimiento_id={$this->est->id}")->json('data.documentos');
    expect(count($snap))->toBe(1)->and($snap[0]['versiones'])->toHaveCount(3);
});

it('422, 403 lectura y 404 ajeno', function () {
    $this->postJson("{$this->base}/documentos", ['tipo' => 'nada', 'titulo' => str_repeat('x', 201), 'archivos' => [['sha256' => 'abc']], 'vence_en' => '2026-01-01', 'emitido_en' => '2026-02-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['tipo', 'titulo', 'archivos.0.sha256', 'archivos.0.bytes', 'vence_en']);
    $this->postJson("{$this->base}/documentos", docBody(['archivos' => []]))->assertStatus(422)->assertJsonValidationErrors(['archivos']);
    $doc = $this->postJson("{$this->base}/documentos", docBody(['archivos' => [archivoRef($this->sha)]]))->assertCreated()->json('data.id');
    $this->postJson("{$this->base}/documentos/{$doc}/versiones", ['accion' => 'nueva_version'])->assertStatus(422)->assertJsonValidationErrors(['motivo', 'archivos']);

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/documentos/{$doc}")->assertOk();
    $this->postJson("{$this->base}/documentos", docBody(['archivos' => [archivoRef($this->sha)]]))->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/documentos")->assertNotFound();
    $this->getJson("{$this->base}/documentos/{$doc}")->assertNotFound();
    $this->postJson("{$this->base}/documentos", [])->assertNotFound();
});
