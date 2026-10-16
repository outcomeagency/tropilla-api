<?php

use App\Domain\Notificaciones\Notificador;
use App\Domain\Notificaciones\Push\FcmPushDriver;
use App\Domain\Notificaciones\Push\PushDriver;
use App\Domain\Notificaciones\ResumenDiarioMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-20 10:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->enc = miembro($this->est, 'encargado');
    $this->admin = miembro($this->est, 'admin');
    $this->disp = (string) Str::uuid7();
    DB::table('dispositivos')->insert(['id' => $this->disp, 'usuario_id' => $this->enc->id, 'plataforma' => 'android', 'fcm_token' => 'tok-encargado', 'created_at' => now(), 'updated_at' => now()]);
});

function avisos(string $usuarioId): array
{
    return DB::table('notificaciones')->where('usuario_id', $usuarioId)->orderBy('tipo')->pluck('tipo')->all();
}

it('alertas del día por rol: tarea, documento, parto y recategorización; push al encargado; sin duplicar', function () {
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Vacunar aftosa', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-10-22'])->assertCreated();
    $this->postJson("{$this->base}/documentos", ['tipo' => 'certificado_sanitario', 'titulo' => 'Brucelosis', 'vence_en' => '2026-10-27',
        'archivos' => [['sha256' => hash('sha256', 'x'), 'bytes' => 1, 'mime' => 'application/pdf']]])->assertCreated();
    $h = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$h], '2026-01-10'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.tacto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$h['animal_id']]],
        'payload' => ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 296], 'ocurrido_en' => '2026-10-20', 'confirmar_advertencias' => true])->assertCreated();
    DB::table('animales_estado')->where('animal_id', $h['animal_id'])->update(['categoria_sugerida_codigo' => 'BUB_BUBILLA']);

    $this->artisan('alertas:generar --sin-resumen')->assertSuccessful();
    expect(avisos($this->dueno->id))->toBe(['documento_vence', 'tarea_sanitaria'])
        ->and(avisos($this->admin->id))->toBe(['documento_vence'])
        ->and(avisos($this->enc->id))->toBe(['parto_proximo', 'recategorizacion', 'tarea_sanitaria']);
    $doc = DB::table('notificaciones')->where('usuario_id', $this->admin->id)->first();
    expect($doc->titulo)->toBe('Brucelosis vence en 7 días');
    $push = app(PushDriver::class)->enviados;
    expect(collect($push)->pluck('token')->unique()->all())->toBe(['tok-encargado'])->and(count($push))->toBe(3);

    $this->artisan('alertas:generar --sin-resumen');
    expect(DB::table('notificaciones')->count())->toBe(6); // dedupe

    $this->travelTo('2026-10-23 10:00:00'); // tarea vencida → aviso nuevo
    $this->artisan('alertas:generar --sin-resumen');
    expect(DB::table('notificaciones')->where('titulo', 'like', 'Vencida:%')->count())->toBe(2);
});

it('bandeja in-app: no leídas, marcar leída, preferencias (push off) y resumen diario por email', function () {
    Notification::fake();
    $this->postJson("{$this->base}/tareas", ['titulo' => 'Vacunar aftosa', 'tipo_evento' => 'sanidad.vacunacion', 'vence_en' => '2026-10-21'])->assertCreated();

    Sanctum::actingAs($this->enc);
    $this->putJson('/api/v1/me/preferencias-notificacion', [['tipo' => 'tarea_sanitaria', 'push' => false, 'email' => false, 'resumen_diario' => true]])->assertNoContent();
    $this->getJson('/api/v1/me/preferencias-notificacion')->assertJsonPath('data.0.resumen_diario', true);
    $this->artisan('alertas:generar')->assertSuccessful();
    expect(app(PushDriver::class)->enviados)->toBe([]); // push desactivado
    Notification::assertSentTo($this->enc, ResumenDiarioMail::class);
    Notification::assertNotSentTo($this->dueno, ResumenDiarioMail::class);

    $n = $this->getJson('/api/v1/me/notificaciones?no_leidas=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.no_leidas', 1)->json('data.0');
    expect($n['tipo'])->toBe('tarea_sanitaria')->and($n['datos']['ruta'])->toBe('/sanidad/tareas');
    $this->postJson("/api/v1/me/notificaciones/{$n['id']}/leer")->assertNoContent();
    $this->getJson('/api/v1/me/notificaciones?no_leidas=1')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/me/notificaciones')->assertJsonCount(1, 'data');

    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/me/notificaciones/{$n['id']}/leer")->assertNotFound(); // ajena
    $this->putJson('/api/v1/me/preferencias-notificacion', [['tipo' => 'cualquiera', 'push' => 'si']])->assertStatus(422);
    $this->putJson('/api/v1/me/preferencias-notificacion', [['establecimiento_id' => (string) crearEstablecimiento()->id, 'tipo' => 'refuerzo', 'push' => true, 'email' => false, 'resumen_diario' => false]])->assertNotFound();
});

it('consulta de comprador → aviso in-app al dueño/admin; FCM HTTP v1 con cuenta de servicio y token inválido', function () {
    $this->postJson('/api/v1/public/consultas', ['establecimiento_slug' => 'x', 'nombre' => 'Li'])->assertStatus(422);
    $id = (string) Str::uuid7();
    app(Notificador::class)->aRoles($this->est->id, ['dueno', 'admin'], 'consulta_nueva', 'Nueva consulta', 'Desde CN', ['consulta_id' => $id], "consulta:{$id}");
    expect(avisos($this->dueno->id))->toBe(['consulta_nueva'])->and(avisos($this->enc->id))->toBe([]);

    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x']), 'fcm.googleapis.com/*' => Http::sequence()
        ->push(['name' => 'projects/p/messages/1'])->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)]);
    $fcm = new FcmPushDriver(['project_id' => 'tropilla-dev', 'client_email' => 'svc@tropilla-dev.iam.gserviceaccount.com', 'private_key' => $pem]);
    expect($fcm->enviar('tok', 'Hola', 'Cuerpo', ['tipo' => 'x']))->toBeTrue()->and($fcm->enviar('tok', 'Hola', 'Cuerpo', []))->toBeFalse();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/projects/tropilla-dev/messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.x')
        && $r['message']['notification']['title'] === 'Hola');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2') && count(explode('.', $r['assertion'])) === 3);
});
