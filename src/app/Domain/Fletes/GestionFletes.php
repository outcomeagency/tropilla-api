<?php

namespace App\Domain\Fletes;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transiciones del pedido de flete (TPA-73). Cada una deja constancia en `fletes_bitacora`.
 * pendiente → en_gestion → propuesto → contactado (el productor aceptó una propuesta) → acordado | sin_acuerdo; cancelado en cualquier punto abierto.
 */
final class GestionFletes
{
    public function __construct(private readonly MatchingFletes $matching, private readonly Notificador $notificador) {}

    /** @param array<string, mixed> $datos */
    public function crearPedido(Establecimiento $est, User $usuario, array $datos): string
    {
        $origen = $datos['origen_departamento_codigo'] ?? null ?: $est->departamento_codigo;
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($est, $usuario, $datos, $origen, $id) {
            DB::table('fletes_pedidos')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'usuario_id' => $usuario->id,
                'origen_departamento_codigo' => $origen, 'destino_provincia_codigo' => $datos['destino_provincia_codigo'],
                'destino_localidad' => trim($datos['destino_localidad']), 'motivo' => $datos['motivo'],
                'fecha_desde' => $datos['fecha_desde'], 'fecha_hasta' => $datos['fecha_hasta'], 'especie_codigo' => $datos['especie_codigo'],
                'categoria' => $datos['categoria'] ?? null, 'cabezas' => $datos['cabezas'], 'kg_promedio' => $datos['kg_promedio'] ?? null,
                'jaula_preferida' => $datos['jaula_preferida'] ?? null, 'observaciones' => $datos['observaciones'] ?? null,
                'telefono_contacto' => trim($datos['telefono_contacto']),
                'en_corredor' => in_array(substr($origen, 0, 2), Fletes::corredor(), true),
                'consentimiento' => json_encode(['acepta' => true, 'texto' => Fletes::CONSENTIMIENTO, 'version' => Fletes::CONSENTIMIENTO_VERSION,
                    'aceptado_en' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
                'estado' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Fletes::bitacora($usuario->id, 'pedido_creado', $id);
        });

        return $id;
    }

    public function tomar(object $pedido, User $staff): void
    {
        if ($pedido->estado !== 'pendiente') {
            throw ApiException::reglaBloqueante('El pedido ya está en gestión o cerrado.');
        }
        DB::transaction(function () use ($pedido, $staff) {
            DB::table('fletes_pedidos')->where('id', $pedido->id)->update(['estado' => 'en_gestion', 'updated_at' => now()]);
            Fletes::bitacora($staff->id, 'pedido_en_gestion', $pedido->id);
        });
    }

    /** @param array<string, mixed> $datos */
    public function proponer(object $pedido, User $staff, array $datos): string
    {
        if (! in_array($pedido->estado, ['pendiente', 'en_gestion', 'propuesto'], true)) {
            throw ApiException::reglaBloqueante('Solo se pueden proponer camiones a pedidos abiertos que todavía no tienen un transportista aceptado.');
        }
        $t = DB::table('fletes_transportistas')->where('id', $datos['transportista_id'])->first() ?? throw ApiException::noEncontrado();
        if (! $t->activo) {
            throw ApiException::reglaBloqueante('El transportista está inactivo.');
        }
        $doc = Fletes::documentacion($t->id);
        if (! $doc['completa']) {
            throw ApiException::reglaBloqueante('Antes de proponerlo hay que verificar toda su documentación.', ['transportista_id' => array_map(
                fn ($i) => 'Falta verificar: '.Fletes::DOCUMENTOS_TRANSPORTISTA[$i], $doc['faltantes'])]);
        }
        $disp = null;
        if (! empty($datos['disponibilidad_id'])) {
            $disp = DB::table('fletes_disponibilidades')->where('id', $datos['disponibilidad_id'])->where('transportista_id', $t->id)->first()
                ?? throw ApiException::validacion('La disponibilidad no es de ese transportista.', ['disponibilidad_id' => ['La disponibilidad no es de ese transportista.']]);
            if ($disp->estado !== 'abierta') {
                throw ApiException::reglaBloqueante('La disponibilidad está cerrada.');
            }
        }
        if ($datos['fecha_propuesta'] < $pedido->fecha_desde || $datos['fecha_propuesta'] > $pedido->fecha_hasta) {
            throw ApiException::validacion('La fecha propuesta tiene que estar dentro de la ventana del pedido.', ['fecha_propuesta' => ['Fuera de la ventana del pedido.']]);
        }
        if (DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('transportista_id', $t->id)->where('estado', 'propuesta')->exists()) {
            throw ApiException::reglaBloqueante('Ya hay una propuesta pendiente de este transportista para el pedido.');
        }
        $compat = $this->matching->compatibilidad($pedido, $disp, $t->id);
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($pedido, $staff, $datos, $t, $disp, $compat, $id) {
            DB::table('fletes_propuestas')->insert([
                'id' => $id, 'pedido_id' => $pedido->id, 'transportista_id' => $t->id, 'disponibilidad_id' => $disp?->id,
                'compatibilidad' => json_encode($compat, JSON_UNESCAPED_UNICODE), 'tarifa_estimada' => $datos['tarifa_estimada'] ?? null,
                'fecha_propuesta' => $datos['fecha_propuesta'], 'nota' => $datos['nota'] ?? null, 'estado' => 'propuesta',
                'propuesta_por' => $staff->id, 'created_at' => now(),
            ]);
            DB::table('fletes_pedidos')->where('id', $pedido->id)->update(['estado' => 'propuesto', 'updated_at' => now()]);
            Fletes::bitacora($staff->id, 'propuesta_enviada', $pedido->id, $t->id, ['propuesta_id' => $id, 'transportista' => $t->nombre,
                'puntaje' => $compat['puntaje'] ?? null]);
            $this->notificador->aRoles($pedido->establecimiento_id, ['dueno', 'admin', 'encargado'], 'flete_propuesta', 'Tenés una propuesta de flete',
                "El equipo de TROPILLA te propone a {$t->nombre} para el traslado de {$pedido->cabezas} cabezas. Revisala y decidí.",
                ['pedido_id' => $pedido->id, 'propuesta_id' => $id], 'flete_propuesta|'.$id);
        });

        return $id;
    }

    public function retirar(object $propuesta, User $staff, ?string $motivo): void
    {
        if ($propuesta->estado !== 'propuesta') {
            throw ApiException::reglaBloqueante('Solo se puede retirar una propuesta pendiente.');
        }
        DB::transaction(function () use ($propuesta, $staff, $motivo) {
            DB::table('fletes_propuestas')->where('id', $propuesta->id)->update(['estado' => 'retirada', 'motivo_rechazo' => $motivo, 'respondida_en' => now()]);
            Fletes::bitacora($staff->id, 'propuesta_retirada', $propuesta->pedido_id, $propuesta->transportista_id, ['propuesta_id' => $propuesta->id, 'motivo' => $motivo]);
            $this->reabrirSiNoQuedan($propuesta->pedido_id);
        });
    }

    public function aceptar(object $pedido, object $propuesta, User $usuario): void
    {
        if ($propuesta->estado !== 'propuesta' || $pedido->estado !== 'propuesto') {
            throw ApiException::reglaBloqueante('Esta propuesta ya no está disponible.');
        }
        if (! Fletes::documentacion($propuesta->transportista_id)['completa']) {
            throw ApiException::reglaBloqueante('La documentación del transportista venció o quedó observada desde que se propuso. El equipo la va a revisar.');
        }
        DB::transaction(function () use ($pedido, $propuesta, $usuario) {
            DB::table('fletes_propuestas')->where('id', $propuesta->id)->update(['estado' => 'aceptada', 'respondida_en' => now()]);
            DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('estado', 'propuesta')->where('id', '!=', $propuesta->id)
                ->update(['estado' => 'retirada', 'motivo_rechazo' => 'El productor aceptó otra propuesta.', 'respondida_en' => now()]);
            DB::table('fletes_pedidos')->where('id', $pedido->id)->update(['estado' => 'contactado', 'updated_at' => now()]);
            Fletes::bitacora($usuario->id, 'propuesta_aceptada', $pedido->id, $propuesta->transportista_id, ['propuesta_id' => $propuesta->id]);
            Fletes::bitacora($usuario->id, 'contacto_compartido', $pedido->id, $propuesta->transportista_id, [
                'datos' => ['nombre', 'telefono', 'zona_de_carga', 'fechas', 'destino', 'cabezas'], 'consentimiento_version' => json_decode($pedido->consentimiento, true)['version'] ?? null]);
        });
    }

    public function rechazar(object $pedido, object $propuesta, User $usuario, ?string $motivo): void
    {
        if ($propuesta->estado !== 'propuesta') {
            throw ApiException::reglaBloqueante('Esta propuesta ya no está pendiente.');
        }
        DB::transaction(function () use ($pedido, $propuesta, $usuario, $motivo) {
            DB::table('fletes_propuestas')->where('id', $propuesta->id)->update(['estado' => 'rechazada', 'motivo_rechazo' => $motivo, 'respondida_en' => now()]);
            Fletes::bitacora($usuario->id, 'propuesta_rechazada', $pedido->id, $propuesta->transportista_id, ['propuesta_id' => $propuesta->id, 'motivo' => $motivo]);
            $this->reabrirSiNoQuedan($pedido->id);
        });
    }

    public function cancelar(object $pedido, User $usuario, ?string $motivo): void
    {
        if (! in_array($pedido->estado, Fletes::ESTADOS_ABIERTOS, true)) {
            throw ApiException::reglaBloqueante('El pedido ya está cerrado.');
        }
        DB::transaction(function () use ($pedido, $usuario, $motivo) {
            DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('estado', 'propuesta')
                ->update(['estado' => 'retirada', 'motivo_rechazo' => 'Pedido cancelado.', 'respondida_en' => now()]);
            DB::table('fletes_pedidos')->where('id', $pedido->id)->update(['estado' => 'cancelado', 'updated_at' => now()]);
            Fletes::bitacora($usuario->id, 'pedido_cancelado', $pedido->id, null, ['motivo' => $motivo]);
        });
    }

    /** @param array<string, mixed> $datos */
    public function resultado(object $pedido, User $usuario, array $datos): void
    {
        if (! in_array($pedido->estado, Fletes::ESTADOS_ABIERTOS, true)) {
            throw ApiException::reglaBloqueante('El pedido ya tiene resultado o fue cancelado.');
        }
        $aceptada = DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('estado', 'aceptada')->first();
        if ($datos['resultado'] === 'acordado' && $aceptada === null) {
            throw ApiException::reglaBloqueante('Para registrar un flete acordado primero hay que aceptar una propuesta.');
        }
        DB::transaction(function () use ($pedido, $usuario, $datos, $aceptada) {
            DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('estado', 'propuesta')
                ->update(['estado' => 'retirada', 'motivo_rechazo' => 'Pedido cerrado.', 'respondida_en' => now()]);
            DB::table('fletes_pedidos')->where('id', $pedido->id)->update([
                'estado' => $datos['resultado'], 'resultado' => $datos['resultado'], 'resultado_costo' => $datos['costo'] ?? null,
                'resultado_km' => $datos['km'] ?? null, 'resultado_nota' => $datos['nota'] ?? null, 'resultado_en' => now(), 'updated_at' => now(),
            ]);
            Fletes::bitacora($usuario->id, 'resultado_registrado', $pedido->id, $aceptada?->transportista_id, [
                'resultado' => $datos['resultado'], 'costo' => $datos['costo'] ?? null, 'km' => $datos['km'] ?? null]);
        });
    }

    private function reabrirSiNoQuedan(string $pedidoId): void
    {
        $quedan = DB::table('fletes_propuestas')->where('pedido_id', $pedidoId)->where('estado', 'propuesta')->exists();
        if (! $quedan) {
            DB::table('fletes_pedidos')->where('id', $pedidoId)->where('estado', 'propuesto')->update(['estado' => 'en_gestion', 'updated_at' => now()]);
        }
    }
}
