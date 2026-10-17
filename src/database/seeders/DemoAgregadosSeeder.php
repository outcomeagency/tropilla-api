<?php

namespace Database\Seeders;

use App\Domain\Agregados\DatasetSintetico;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo del tablero agregado (TPA-75), solo entorno local: dataset sintético, una licencia de frigorífico (existencias y precios,
 * sin cobertura sanitaria) con su analista y una cuenta del equipo para la gobernanza. Idempotente.
 *   analista@tropilla.test / tropilla-demo · equipo@tropilla.test / tropilla-demo (es_staff)
 */
class DemoAgregadosSeeder extends Seeder
{
    public const PASSWORD = 'tropilla-demo';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        app(DatasetSintetico::class)->asegurar();
        $equipo = User::query()->firstOrCreate(['email' => 'equipo@tropilla.test'], ['nombre' => 'Equipo TROPILLA', 'password' => self::PASSWORD, 'email_verificado_en' => now()]);
        $equipo->forceFill(['es_staff' => true])->save();
        $analista = User::query()->firstOrCreate(['email' => 'analista@tropilla.test'], ['nombre' => 'Valeria Gómez', 'password' => self::PASSWORD, 'email_verificado_en' => now()]);

        $cliente = DB::table('agregados_clientes')->where('nombre', 'Frigorífico del Litoral (demo)')->value('id');
        if ($cliente === null) {
            $cliente = (string) Str::uuid7();
            DB::table('agregados_clientes')->insert(['id' => $cliente, 'nombre' => 'Frigorífico del Litoral (demo)', 'tipo' => 'frigorifico',
                'finalidad' => 'Planeamiento de abastecimiento de hacienda por zona y trimestre. Prohibido reidentificar establecimientos o cruzar con otras fuentes.',
                'contrato_version' => 'piloto-agregados-v0', 'vigente_hasta' => now()->addYear()->toDateString(), 'consultas_por_dia' => 25,
                'metricas' => '{existencias,precios}', 'estado' => 'activo', 'creado_por' => $equipo->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (! DB::table('agregados_cliente_miembros')->where('cliente_id', $cliente)->where('usuario_id', $analista->id)->where('estado', 'activa')->exists()) {
            DB::table('agregados_cliente_miembros')->insert(['id' => (string) Str::uuid7(), 'cliente_id' => $cliente, 'usuario_id' => $analista->id,
                'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
