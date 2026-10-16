<?php

use App\Domain\Perfil\Services\Perfiles;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento(); // Corrientes (18), Mercedes (18105)
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    $this->dueno = duenoDe($this->est);
    Perfiles::asegurar($this->est);
    DB::table('perfiles_publicos')->where('establecimiento_id', $this->est->id)->update(['slug' => 'la-esperanza', 'publicado' => true, 'preview_aceptada_en' => now()]);
    Sanctum::actingAs($this->dueno);
    $lote = crearLote($this->est, 'BUB', true, 'Bubillos');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUBILLO' => 30]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $this->comprador = User::factory()->create(['nombre' => 'Li Wei', 'email_verificado_en' => now()]);
    DB::table('compradores')->insert(['usuario_id' => $this->comprador->id, 'empresa' => 'Shandong Meat', 'tipo' => 'exportador', 'pais' => 'CN', 'idiomas' => '{en}', 'created_at' => now(), 'updated_at' => now()]);
});

it('Busco: publicar → calza con la hacienda (aviso) → el productor responde adentro → el comprador lee sin exponer contacto; cerrar', function () {
    Sanctum::actingAs($this->comprador);
    $b = $this->postJson('/api/v1/comprador/buscos', ['especie' => 'BUB', 'categorias' => ['BUB_BUBILLO'], 'cantidad' => 25, 'zona' => ['provincias' => ['18']],
        'plazo_hasta' => now()->addMonth()->toDateString(), 'mensaje' => 'Buffalo steers for export'])->assertCreated()
        ->assertJsonPath('data.estado', 'abierta')->assertJsonPath('data.comprador.empresa', 'Shandong Meat')->json('data');
    // Otro que no calza (zona y cantidad).
    $this->postJson('/api/v1/comprador/buscos', ['especie' => 'BUB', 'categorias' => ['BUB_BUBILLO'], 'cantidad' => 10, 'zona' => ['provincias' => ['02']]])->assertCreated();
    expect(DB::table('notificaciones')->where('usuario_id', $this->dueno->id)->where('tipo', 'busco_coincide')->count())->toBe(1);

    Sanctum::actingAs($this->dueno);
    $d = $this->getJson("{$this->base}/demandas")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($d['puntaje'])->toBe(100)->and($d['respondida'])->toBeFalse()->and($d['busco']['comprador'])->toBe(['tipo' => 'exportador', 'pais' => 'CN'])
        ->and($d['motivo']['cabezas'])->toBe(30);
    $this->postJson("{$this->base}/demandas/{$b['id']}/respuestas", ['mensaje' => 'Tenemos 30 bubillos Mediterráneos, entrega en noviembre.'])->assertCreated()
        ->assertJsonPath('data.establecimiento.slug', 'la-esperanza');
    $this->getJson("{$this->base}/demandas")->assertJsonPath('data.0.respondida', true);

    Sanctum::actingAs($this->comprador);
    expect(collect($this->getJson('/api/v1/comprador/buscos')->json('data'))->firstWhere('id', $b['id'])['respuestas_count'])->toBe(1);
    $this->getJson("/api/v1/comprador/buscos/{$b['id']}/respuestas")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.establecimiento.nombre', 'La Esperanza');
    expect(DB::table('notificaciones')->where('usuario_id', $this->comprador->id)->where('tipo', 'busco_respuesta')->count())->toBe(1);

    $this->getJson('/api/v1/public/buscos?especie=BUB&provincia=18')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['nombre' => 'Li Wei']);
    $this->patchJson("/api/v1/comprador/buscos/{$b['id']}", ['cantidad' => 50, 'mostrar_nombre' => true])->assertOk()->assertJsonPath('data.cantidad', 50);
    $this->getJson('/api/v1/public/buscos?provincia=18')->assertJsonPath('data.0.comprador.nombre', 'Li Wei');
    Sanctum::actingAs($this->dueno);
    $this->getJson("{$this->base}/demandas")->assertJsonPath('data.0.puntaje', 80); // ya no alcanzan las cabezas
    Sanctum::actingAs($this->comprador);
    $this->postJson("/api/v1/comprador/buscos/{$b['id']}/cerrar")->assertOk()->assertJsonPath('data.estado', 'cerrada');
    $this->patchJson("/api/v1/comprador/buscos/{$b['id']}", ['cantidad' => 5])->assertStatus(422);
    Sanctum::actingAs($this->dueno);
    $this->getJson("{$this->base}/demandas")->assertJsonCount(0, 'data');
    $this->postJson("{$this->base}/demandas/{$b['id']}/respuestas", ['mensaje' => 'Hola de nuevo'])->assertNotFound();
});

it('perfil de profesional con matrícula declarada, zona e idiomas; «trabaja con» solo si el productor autoriza', function () {
    $vet = User::factory()->create(['nombre' => 'Juan Pérez']);
    Sanctum::actingAs($vet);
    $this->getJson('/api/v1/me/profesional')->assertNotFound();
    $this->putJson('/api/v1/me/profesional', ['slug' => 'dr-perez', 'nombre_publico' => 'Dr. Juan Pérez', 'profesion' => 'veterinario', 'matricula_declarada' => 'MP 1234',
        'especialidades' => ['reproducción bubalina'], 'zonas' => ['provincias' => ['18', '22']], 'idiomas' => ['es', 'pt'], 'publico' => true])->assertOk()
        ->assertJsonPath('data.idiomas', ['es', 'pt'])->assertJsonPath('data.publico', true);
    $this->getJson('/api/v1/public/profesionales/dr-perez')->assertOk()->assertJsonPath('data.matricula_declarada', 'MP 1234')->assertJsonCount(0, 'data.establecimientos')
        ->assertJson(fn ($j) => $j->where('data.leyenda', fn ($l) => str_contains($l, 'no verificada'))->etc());

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->postJson("{$this->base}/profesionales", ['usuario_id' => $vet->id])->assertForbidden();
    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/profesionales", ['usuario_id' => $vet->id, 'visible_en_perfil' => true])->assertNoContent();
    $this->getJson('/api/v1/public/profesionales/dr-perez')->assertJsonPath('data.establecimientos.0.slug', 'la-esperanza');
    $this->postJson("{$this->base}/profesionales", ['usuario_id' => $this->dueno->id])->assertStatus(422);

    Sanctum::actingAs(User::factory()->create());
    $this->putJson('/api/v1/me/profesional', ['slug' => 'dr-perez', 'nombre_publico' => 'X', 'profesion' => 'brujo'])->assertStatus(422)
        ->assertJsonValidationErrors(['slug', 'nombre_publico', 'profesion']);
    Sanctum::actingAs($vet);
    $this->putJson('/api/v1/me/profesional', ['slug' => 'dr-perez', 'nombre_publico' => 'Dr. Juan Pérez', 'profesion' => 'veterinario', 'publico' => false])->assertOk();
    $this->getJson('/api/v1/public/profesionales/dr-perez')->assertNotFound();
});

it('Busco exige perfil de comprador y email; 422; demandas: 403 encargado y 404 ajeno', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/comprador/buscos', ['especie' => 'BUB'])->assertStatus(403)->assertJsonPath('code', 'perfil_comprador_requerido');
    Sanctum::actingAs($this->comprador);
    $this->postJson('/api/v1/comprador/buscos', ['especie' => 'BUB', 'categorias' => ['BOV_VACA'], 'cantidad' => 0, 'zona' => 'todas', 'plazo_hasta' => '2020-01-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['categorias.0', 'cantidad', 'zona', 'plazo_hasta']);
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/demandas")->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/demandas")->assertNotFound();
});
