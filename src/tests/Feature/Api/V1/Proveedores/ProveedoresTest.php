<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function proveedorDemo(User $user, array $override = []): array
{
    DB::table('rubros_proveedor')->updateOrInsert(['codigo' => 'veterinaria'], ['nombres' => json_encode(['es' => 'Veterinaria', 'en' => 'Veterinary', 'pt' => 'Veterinária']), 'activo' => true]);

    return array_replace(['slug' => 'veterinaria-esteros', 'nombre_publico' => 'Veterinaria Esteros', 'razon_social_declarada' => 'Veterinaria Esteros SRL', 'cuit' => '30712345671',
        'rubros' => ['veterinaria'], 'provincias' => ['18'], 'cobertura' => ['type' => 'MultiPolygon', 'coordinates' => [[[[-59, -29], [-57, -29], [-57, -27], [-59, -27], [-59, -29]]]]],
        'descripciones' => ['es' => 'Atención veterinaria para rodeos bovinos y bubalinos del litoral.', 'en' => 'Veterinary care for cattle and buffalo herds in the region.', 'pt' => 'Atendimento veterinário para rebanhos bovinos e bubalinos da região.'],
        'idiomas' => ['es', 'pt'], 'publico' => true], $override);
}

it('coteja CUIT con BCRA, guarda cobertura PostGIS e i18n; la búsqueda pública oculta información privada', function () {
    Http::fake(['*' => Http::response(['results' => ['denominacion' => 'Veterinaria Esteros SRL', 'periodos' => [], 'causales' => []]], 200)]);
    $u = User::factory()->create(['email_verificado_en' => now()]);
    Sanctum::actingAs($u);
    $this->putJson('/api/v1/me/proveedor', proveedorDemo($u))->assertOk()->assertJsonPath('data.estado_cuit', 'validado')->assertJsonPath('data.cuit', null)
        ->assertJsonPath('data.razon_social_informada', 'Veterinaria Esteros SRL')->assertJsonPath('data.fuente_cuit', 'bcra_central_deudores');
    $sinCuit = proveedorDemo($u);
    unset($sinCuit['cuit']);
    $this->putJson('/api/v1/me/proveedor', $sinCuit)->assertOk()->assertJsonPath('data.estado_cuit', 'validado');
    Http::assertSentCount(3); // Editar el perfil no obliga a volver a ingresar ni consultar el CUIT.

    $this->getJson('/api/v1/public/directorio/proveedores?rubro=veterinaria&provincia=18&lat=-28&lon=-58&locale=pt')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Veterinaria Esteros')->assertJsonPath('data.0.es_demo', false)
        ->assertJsonMissing(['cuit' => '30712345671'])->assertJsonMissing(['cobertura' => true]);
    $this->getJson('/api/v1/public/proveedores/veterinaria-esteros?locale=en')->assertOk()
        ->assertJsonPath('data.descripcion', 'Veterinary care for cattle and buffalo herds in the region.')
        ->assertJsonMissingPath('data.cuit')->assertJsonMissingPath('data.razon_social_declarada');
    $this->getJson('/api/v1/public/directorio/proveedores/rubros')->assertOk()->assertJsonPath('data.0.codigo', 'veterinaria');
});

it('valida CUIT, publicación requiere email verificado y la cobertura debe ser geometría válida', function () {
    Http::fake(['*' => Http::response(['results' => ['denominacion' => 'Otra razón social', 'periodos' => [], 'causales' => []]], 200)]);
    $u = User::factory()->create(['email_verificado_en' => null]);
    Sanctum::actingAs($u);
    $this->putJson('/api/v1/me/proveedor', proveedorDemo($u, ['cuit' => '30712345678']))->assertStatus(422)->assertJsonValidationErrors('cuit');
    $this->putJson('/api/v1/me/proveedor', proveedorDemo($u))->assertForbidden()->assertJsonPath('code', 'email_no_verificado');

    $u->forceFill(['email_verificado_en' => now()])->save();
    $invalid = proveedorDemo($u);
    $invalid['cobertura']['coordinates'] = [[[[-59, -29], [-57, -27], [-57, -29], [-59, -27], [-59, -29]]]];
    $this->putJson('/api/v1/me/proveedor', $invalid)->assertStatus(422);
});

it('el productor inicia un lead sin ceder sus datos y el proveedor recibe aviso; no hay reseña sin operación registrada', function () {
    Http::fake(['*' => Http::response(['results' => ['denominacion' => 'Veterinaria Esteros SRL', 'periodos' => [], 'causales' => []]], 200)]);
    $est = crearEstablecimiento();
    $dueno = duenoDe($est);
    $proveedor = User::factory()->create(['email_verificado_en' => now()]);
    Sanctum::actingAs($proveedor);
    $this->putJson('/api/v1/me/proveedor', proveedorDemo($proveedor))->assertOk();

    Sanctum::actingAs($dueno);
    $lead = $this->postJson('/api/v1/proveedores/veterinaria-esteros/leads', ['establecimiento_id' => $est->id, 'rubro' => 'veterinaria', 'mensaje' => 'Necesito presupuesto para una visita sanitaria.'])
        ->assertCreated()->assertJsonPath('data.estado', 'nuevo')->json('data.id');
    expect(DB::table('leads_proveedor')->where('id', $lead)->value('iniciado_por'))->toBe($dueno->id);
    expect(DB::table('notificaciones')->where('usuario_id', $proveedor->id)->where('tipo', 'lead_proveedor')->count())->toBe(1);
    $this->postJson('/api/v1/proveedores/veterinaria-esteros/operaciones/'.Str::uuid().'/resenas', ['puntaje' => 5])->assertNotFound();
    $operacion = (string) Str::uuid7();
    DB::table('operaciones')->insert(['id' => $operacion, 'establecimiento_id' => $est->id, 'tipo' => 'compra', 'estado' => 'registrada', 'version_vigente' => 1,
        'especie_codigo' => 'BOV', 'categoria_codigo' => 'BOV_VACA', 'cantidad' => 2, 'fecha' => now()->toDateString(), 'modalidad' => 'en_pie', 'precio_kg' => '100.0000',
        'moneda' => 'ARS', 'neto_total' => '200.00', 'neto_kg_vivo' => '2.0000', 'proveedor_id' => $proveedor->id, 'creado_por' => $dueno->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson("/api/v1/proveedores/veterinaria-esteros/operaciones/{$operacion}/resenas", ['puntaje' => 5, 'comentario' => 'Atención clara y en el plazo acordado.'])->assertCreated();
    $this->postJson("/api/v1/proveedores/veterinaria-esteros/operaciones/{$operacion}/resenas", ['puntaje' => 4])->assertStatus(409);
    expect(DB::table('resenas_proveedor')->where('operacion_id', $operacion)->count())->toBe(1);

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson('/api/v1/proveedores/veterinaria-esteros/leads', ['establecimiento_id' => $est->id, 'rubro' => 'veterinaria', 'mensaje' => 'Consulta desde otro campo'])
        ->assertNotFound();
});
