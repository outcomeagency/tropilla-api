<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    config(['tropilla.archivos.driver' => 'local', 'tropilla.archivos.parte_bytes' => 10, 'tropilla.archivos.multipart_desde_bytes' => 25]);
    $this->est = crearEstablecimiento();
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'), ['app']);
});

/** PUT crudo a la URL firmada (como hace el teléfono contra S3) → ETag. */
function putParte(object $t, string $url, string $bytes): string
{
    $r = $t->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $bytes)->assertOk();

    return trim((string) $r->headers->get('ETag'), '"');
}

it('simple: inicia, sube directo a la URL firmada, completa verificando el SHA-256; reintento → completo sin volver a subir', function () {
    $foto = 'jpeg-chico';
    $sha = hash('sha256', $foto);
    $s = $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => strlen($foto), 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])
        ->assertCreated()->assertJsonPath('data.estado', 'iniciada')->assertJsonPath('data.modo', 'simple')->json('data');
    expect($s['url'])->toContain('signature=')->and($s['expira_en'])->not->toBeNull();
    $this->getJson("/api/v1/archivos/{$sha}/estado")->assertJsonPath('data.estado', 'pendiente');

    putParte($this, $s['url'], $foto);
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar", ['partes' => []])->assertOk()
        ->assertJsonPath('data.estado', 'completo')->assertJsonPath('data.bytes', strlen($foto));
    expect(Storage::get(sprintf('archivos/sha256/%s/%s/%s', substr($sha, 0, 2), substr($sha, 2, 2), $sha)))->toBe($foto);

    $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => strlen($foto), 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])
        ->assertOk()->assertJsonPath('data.estado', 'completo')->assertJsonPath('data.subida_id', null);
    $this->get("/api/v1/archivos/{$sha}")->assertRedirect(); // 302 a la URL firmada (5 min)
});

it('multipart por partes con reintento de una parte; contenido que no coincide → corrupto y se descarta', function () {
    $pdf = str_repeat('0123456789', 3).'fin'; // 33 bytes → 4 partes de 10
    $sha = hash('sha256', $pdf);
    $s = $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => strlen($pdf), 'mime' => 'application/pdf', 'establecimiento_id' => $this->est->id])
        ->assertCreated()->assertJsonPath('data.modo', 'multipart')->assertJsonPath('data.parte_bytes', 10)->assertJsonCount(4, 'data.partes')->json('data');
    $etags = [];
    foreach ($s['partes'] as $p) {
        $trozo = substr($pdf, ($p['numero'] - 1) * 10, 10);
        if ($p['numero'] === 2) {
            putParte($this, $p['url'], 'cortado'); // se corta la señal…
        }
        $etags[] = ['numero' => $p['numero'], 'etag' => putParte($this, $p['url'], $trozo)]; // …y se reintenta la parte
    }
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar", ['partes' => array_slice($etags, 0, 3)])->assertStatus(422);
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar", ['partes' => $etags])->assertOk()->assertJsonPath('data.estado', 'completo');

    $falso = hash('sha256', 'otro contenido');
    $s2 = $this->postJson('/api/v1/archivos/subidas', ['sha256' => $falso, 'bytes' => 5, 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])->json('data');
    putParte($this, $s2['url'], 'trucho');
    $this->postJson("/api/v1/archivos/subidas/{$s2['subida_id']}/completar")->assertStatus(422)->assertJsonPath('code', 'archivo_corrupto');
    expect(DB::table('archivos')->where('sha256', $falso)->value('estado'))->toBe('corrupto')
        ->and(Storage::exists(sprintf('archivos/sha256/%s/%s/%s', substr($falso, 0, 2), substr($falso, 2, 2), $falso)))->toBeFalse();
    $this->postJson("/api/v1/archivos/subidas/{$s2['subida_id']}/completar")->assertStatus(422)->assertJsonPath('code', 'subida_vencida');
});

it('vencimiento, firma obligatoria, acceso por referencia y permisos', function () {
    $sha = hash('sha256', 'x');
    $s = $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => 1, 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])->json('data');
    $this->call('PUT', explode('?', $s['url'])[0], [], [], [], [], 'x')->assertForbidden(); // sin firma

    $this->travel(25)->hours();
    $this->artisan('archivos:expirar-subidas')->assertSuccessful();
    expect(DB::table('subidas')->where('id', $s['subida_id'])->value('estado'))->toBe('expirada');
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar")->assertStatus(422)->assertJsonPath('code', 'subida_vencida');

    $this->postJson('/api/v1/archivos/subidas', ['sha256' => 'xyz', 'bytes' => 0, 'mime' => '', 'establecimiento_id' => $this->est->id])
        ->assertStatus(422)->assertJsonValidationErrors(['sha256', 'bytes', 'mime']);
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => 1, 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])->assertForbidden();
    $this->getJson("/api/v1/archivos/{$sha}/estado")->assertNotFound(); // nadie lo referencia todavía
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson('/api/v1/archivos/subidas', ['sha256' => $sha, 'bytes' => 1, 'mime' => 'image/jpeg', 'establecimiento_id' => $this->est->id])->assertNotFound();
    $this->postJson("/api/v1/archivos/subidas/{$s['subida_id']}/completar")->assertNotFound();
});
