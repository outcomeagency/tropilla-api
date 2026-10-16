<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function consulta(string $estId, array $extra = []): string
{
    $id = (string) Str::uuid7();
    DB::table('consultas')->insert([
        'id' => $id, 'establecimiento_id' => $estId, 'origen' => 'perfil', 'nombre' => 'Li Wei', 'empresa' => 'Shandong Meat Co.', 'pais' => 'CN',
        'email' => 'li@example.com', 'telefono' => '+86 138 0000 0000', 'tipo_comprador' => 'exportador', 'volumen' => '200 t/año',
        'mensaje' => 'Interested in buffalo meat.', 'idioma' => 'en', 'estado' => 'nueva', 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    return $id;
}

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
});

it('bandeja con estados y links de respuesta prearmados en el idioma del comprador', function () {
    $id = consulta($this->est->id);
    consulta($this->est->id, ['estado' => 'spam']);

    $this->getJson("{$this->base}/consultas")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.idioma', 'en')
        ->assertJsonPath('data.0.whatsapp_url', fn ($u) => str_starts_with($u, 'https://wa.me/8613800000000?text=Hi%20Li%20Wei'))
        ->assertJsonPath('data.0.mailto_url', fn ($u) => str_starts_with($u, 'mailto:li@example.com'));
    $this->getJson("{$this->base}/consultas?estado=spam")->assertJsonCount(1, 'data');

    $this->getJson("{$this->base}/consultas/{$id}")->assertOk();
    expect(DB::table('consultas')->where('id', $id)->value('vista_en'))->not->toBeNull();

    $this->postJson("{$this->base}/consultas/{$id}/estado", ['estado' => 'respondida', 'canal_respuesta' => 'whatsapp'])->assertOk()
        ->assertJsonPath('data.estado', 'respondida')->assertJsonPath('data.canal_respuesta', 'whatsapp')->assertJsonPath('data.respondida_en', fn ($v) => $v !== null);
    $this->postJson("{$this->base}/consultas/{$id}/estado", ['estado' => 'cerrada'])->assertOk();
    $this->postJson("{$this->base}/consultas/{$id}/estado", ['estado' => 'archivada'])->assertStatus(422);
});

it('encargado y asesor no ven consultas (datos personales) → 403; otro establecimiento 404', function () {
    $id = consulta($this->est->id);
    foreach (['encargado', 'asesor', 'lectura'] as $rol) {
        Sanctum::actingAs(miembro($this->est, $rol));
        $this->getJson("{$this->base}/consultas")->assertForbidden();
    }
    Sanctum::actingAs(miembro($this->est, 'admin'));
    $this->getJson("{$this->base}/consultas/{$id}")->assertOk();
    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("/api/v1/establecimientos/{$otro->id}/consultas/{$id}")->assertNotFound();
});
