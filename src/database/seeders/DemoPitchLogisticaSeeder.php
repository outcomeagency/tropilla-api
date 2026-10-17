<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de campos/pastajes y pedidos de flete ficticios para las escenas del pitch.
 * Solo agrega/actualiza UUID propios de esta demo y solo sobre el establecimiento de demo@tropilla.ar.
 * Los avisos indican que son demo y quedan como datos declarados, sin verificación inmobiliaria.
 */
class DemoPitchLogisticaSeeder extends Seeder
{
    private const ESTABLECIMIENTO = '01a14495-5600-7748-88f1-133064d0c1c5';

    private const AVISOS = [
        ['id' => '0199a440-0000-7000-8000-000000000001', 'titulo' => 'Pastaje demo · lomas y pastura en General Paz', 'modalidad' => 'pastaje', 'capacidad' => 120, 'hectareas' => '85.00', 'desde' => '2026-11-01', 'hasta' => '2027-04-30', 'precio' => 'a_convenir', 'agua' => '{aguada_natural,tajamar,bebederos}', 'alambrado' => 'regular', 'instalaciones' => '{manga,corrales,embarcadero}', 'descripcion' => 'Aviso ficticio de demostración. Lomas con pastura implantada y acceso a aguadas; receptividad y disponibilidad a confirmar en visita.'],
        ['id' => '0199a440-0000-7000-8000-000000000002', 'titulo' => 'Pastaje demo · bajos y esteros para búfalos', 'modalidad' => 'pastaje', 'capacidad' => 80, 'hectareas' => '110.00', 'desde' => '2026-12-01', 'hasta' => '2027-05-31', 'precio' => 'a_convenir', 'agua' => '{aguada_natural,rio_arroyo}', 'alambrado' => 'regular', 'instalaciones' => '{manga,embarcadero}', 'descripcion' => 'Aviso ficticio de demostración. Ambiente de estero con acceso al arroyo; carga y manejo sujetos a evaluación conjunta.'],
        ['id' => '0199a440-0000-7000-8000-000000000003', 'titulo' => 'Arrendamiento demo · campo mixto correntino', 'modalidad' => 'arrendamiento', 'capacidad' => 150, 'hectareas' => '240.00', 'desde' => '2027-01-01', 'hasta' => '2027-12-31', 'precio' => 'a_convenir', 'agua' => '{tajamar,perforacion,molino,bebederos}', 'alambrado' => 'bueno', 'instalaciones' => '{manga,corrales,balanza,galpon}', 'descripcion' => 'Aviso ficticio de demostración. Campo mixto con lomas y bajos; superficie, título, condiciones y receptividad requieren revisión de las partes.'],
        ['id' => '0199a440-0000-7000-8000-000000000004', 'titulo' => 'Capitalización demo · recría de bovinos y búfalos', 'modalidad' => 'capitalizacion', 'capacidad' => 100, 'hectareas' => '130.00', 'desde' => '2026-11-15', 'hasta' => '2027-05-15', 'precio' => 'a_convenir', 'agua' => '{aguada_natural,tajamar,bebederos}', 'alambrado' => 'regular', 'instalaciones' => '{manga,corrales,balanza,embarcadero}', 'descripcion' => 'Aviso ficticio de demostración. Propuesta orientativa de recría; porcentajes, sanidad, manejo y responsabilidades se acuerdan entre las partes.'],
    ];

    private const FLETES = [
        ['id' => '0199a450-0000-7000-8000-000000000001', 'destino_provincia' => '22', 'destino' => 'Resistencia', 'motivo' => 'venta_frigorifico', 'especie' => 'BOV', 'categoria' => 'Novillos Braford', 'cabezas' => 32, 'kg' => 430, 'jaula' => 'semi', 'nota' => 'Pedido de demostración; coordinar ventana y documentación antes de confirmar.'],
        ['id' => '0199a450-0000-7000-8000-000000000002', 'destino_provincia' => '34', 'destino' => 'Formosa', 'motivo' => 'pastaje', 'especie' => 'BUB', 'categoria' => 'Búfalas Murrah', 'cabezas' => 24, 'kg' => 510, 'jaula' => 'acoplado', 'nota' => 'Traslado de demostración entre establecimientos; revisar bienestar y accesos.'],
        ['id' => '0199a450-0000-7000-8000-000000000003', 'destino_provincia' => '18', 'destino' => 'Mercedes', 'motivo' => 'remate_feria', 'especie' => 'BOV', 'categoria' => 'Terneros Braford', 'cabezas' => 40, 'kg' => 210, 'jaula' => 'doble_piso', 'nota' => 'Pedido de demostración para feria; DT-e y documentación a cargo de quien corresponda.'],
    ];

    public function run(): void
    {
        $demo = DB::table('usuarios')->where('email', 'demo@tropilla.ar')->first();
        $establecimiento = DB::table('establecimientos as e')
            ->join('membresias as m', 'm.establecimiento_id', '=', 'e.id')
            ->where('e.nombre', 'La Esperanza')->where('m.usuario_id', $demo?->id)
            ->where('m.estado', 'activa')->where('m.rol', 'dueno')->select('e.*')->first();

        if ($demo === null || $establecimiento === null || $establecimiento->id !== self::ESTABLECIMIENTO) {
            $this->command?->warn('No se encontró La Esperanza de demo@tropilla.ar; no se modificó ningún registro.');

            return;
        }

        DB::transaction(function () use ($demo, $establecimiento): void {
            $ahora = now();
            foreach (self::AVISOS as $aviso) {
                DB::table('campo_avisos')->updateOrInsert(['id' => $aviso['id']], [
                    'establecimiento_id' => self::ESTABLECIMIENTO, 'autor_id' => $demo->id,
                    'titulo' => $aviso['titulo'], 'descripcion' => $aviso['descripcion'], 'modalidad' => $aviso['modalidad'],
                    'especies' => '{BOV,BUB}', 'categorias' => '{}',
                    'capacidad_cabezas' => $aviso['capacidad'], 'superficie_ha' => $aviso['hectareas'],
                    'temporada_desde' => $aviso['desde'], 'temporada_hasta' => $aviso['hasta'],
                    'plazo_minimo_meses' => $aviso['modalidad'] === 'arrendamiento' ? 12 : 3,
                    'precio_unidad' => $aviso['precio'], 'precio_valor' => null,
                    'condiciones' => 'Contenido sintético de demostración; visitar y revisar documentación antes de cualquier acuerdo.',
                    'agua' => $aviso['agua'], 'alambrado' => $aviso['alambrado'], 'instalaciones' => $aviso['instalaciones'],
                    'accesos' => 'Acceso rural desde RP 13; estado del camino sujeto a lluvias y confirmación.',
                    'provincia_codigo' => '18', 'departamento_codigo' => '18063', 'localidad' => 'Caá Catí',
                    'mostrar_localidad' => true, 'estado' => 'publicado', 'verificacion' => 'declarado',
                    'verificacion_nota' => null, 'verificado_por' => null, 'verificado_en' => null,
                    'publicado_en' => $ahora, 'cerrado_en' => null, 'motivo_cierre' => null,
                    'row_version' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            $fecha = now()->addDays(14)->toDateString();
            foreach (self::FLETES as $pedido) {
                DB::table('fletes_pedidos')->updateOrInsert(['id' => $pedido['id']], [
                    'establecimiento_id' => $establecimiento->id, 'usuario_id' => $demo->id,
                    'origen_departamento_codigo' => $establecimiento->departamento_codigo,
                    'destino_provincia_codigo' => $pedido['destino_provincia'], 'destino_localidad' => $pedido['destino'],
                    'motivo' => $pedido['motivo'], 'fecha_desde' => $fecha, 'fecha_hasta' => now()->addDays(21)->toDateString(),
                    'especie_codigo' => $pedido['especie'], 'categoria' => $pedido['categoria'], 'cabezas' => $pedido['cabezas'],
                    'kg_promedio' => $pedido['kg'], 'jaula_preferida' => $pedido['jaula'], 'observaciones' => $pedido['nota'],
                    'telefono_contacto' => '+5493794000000', 'en_corredor' => true,
                    'consentimiento' => json_encode(['acepta' => true, 'texto' => 'Consentimiento ficticio para demostración; no contactar transportistas.',
                        'version' => 'demo-pitch-v1', 'aceptado_en' => $ahora->toIso8601String()], JSON_UNESCAPED_UNICODE),
                    'estado' => 'pendiente', 'resultado' => null, 'resultado_costo' => null, 'resultado_km' => null,
                    'resultado_nota' => null, 'resultado_en' => null, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                DB::table('fletes_bitacora')->insertOrIgnore([
                    'pedido_id' => $pedido['id'], 'transportista_id' => null, 'usuario_id' => $demo->id,
                    'accion' => 'pedido_creado_demo',
                    'detalle' => json_encode(['demo' => true, 'nota' => 'Solicitud sintética; no contactar transportistas.'], JSON_UNESCAPED_UNICODE),
                    'id' => $pedido['id'], 'created_at' => $ahora,
                ]);
            }
        });

        $this->command?->info('Demo logística: 4 avisos de campos/pastajes y 3 pedidos de flete (solo La Esperanza).');
    }
}
