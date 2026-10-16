<?php

namespace App\Domain\Contratos\Actions;

use App\Domain\Contratos\Sobres;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Decimales as D;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tomas de tokens (TPA-56/57). Cada transición es un movimiento **append-only** hasheado y encadenado (`tropilla.toma_movimiento/1`);
 * la cabecera `tomas` es su proyección. Siempre bajo `SELECT … FOR UPDATE` del contrato: nunca se comprometen más tokens que los
 * totales (409 `tokens_insuficientes`). El token no se transfiere y el pago es fuera de la app: acá solo se registra quién dijo qué.
 */
final class GestionarTomas
{
    public const ACTIVAS = ['reservada', 'pago_informado', 'confirmada'];

    public function tomar(string $codigo, User $comprador, int $tokens, ?string $id = null): string
    {
        return DB::transaction(function () use ($codigo, $comprador, $tokens, $id) {
            $c = DB::table('contratos_futuro')->where('codigo_publico', strtoupper($codigo))->lockForUpdate()->first() ?? throw ApiException::noEncontrado();
            if ($id && ($t = DB::table('tomas')->where('id', $id)->first())) {
                return $t->comprador_id === $comprador->id ? $t->id : throw new ApiException(409, 'id_reutilizado', __('Ese id ya existe.'));
            }
            if (! in_array($c->estado, ['publicado', 'parcialmente_tomado'], true)) {
                throw new ApiException(409, 'tokens_insuficientes', __('El contrato no tiene tokens libres.'));
            }
            if (DB::table('membresias')->where('establecimiento_id', $c->establecimiento_id)->where('usuario_id', $comprador->id)->where('estado', 'activa')->exists()) {
                throw ApiException::reglaBloqueante(__('No podés tomar tokens de tu propio establecimiento.'));
            }
            $libres = $c->tokens_totales - $c->tokens_comprometidos;
            if ($tokens > $libres) {
                throw new ApiException(409, 'tokens_insuficientes', __('Quedan :n tokens libres.', ['n' => $libres]), ['tokens' => [(string) $libres]]);
            }
            $cadena = Cadena::bloquearDeEstablecimiento($c->establecimiento_id);
            $id ??= (string) Str::uuid7();
            DB::table('tomas')->insert([
                'id' => $id, 'contrato_id' => $c->id, 'establecimiento_id' => $c->establecimiento_id, 'comprador_id' => $comprador->id,
                'contrato_version' => $c->version_vigente, 'tokens' => $tokens, 'tokens_vigentes' => $tokens, 'kg' => $tokens * $c->kg_por_token,
                'precio_especial_kg' => $c->precio_especial_kg, 'anticipo_monto' => D::redondear(D::mul((string) $tokens, (string) $c->anticipo_por_token), 2),
                'estado' => 'reservada', 'vence_en' => now()->addHours((int) $c->toma_vencimiento_horas), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->movimiento($cadena, $id, 'reservada', ['tokens' => $tokens], $comprador->id, 'comprador');
            $this->recalcularContrato($c->id);
            app(Notificador::class)->aRoles($c->establecimiento_id, ['dueno', 'admin'], 'toma_nueva', "Tomaron {$tokens} tokens del contrato {$c->codigo_publico}",
                'Esperá que informe el pago y confirmá «recibí».', ['contrato_id' => $c->id, 'toma_id' => $id, 'ruta' => "/comercial/contratos/{$c->id}"], "toma:{$id}:reservada");

            return $id;
        });
    }

    /** @param array{referencia?: ?string, fecha_pago?: ?string} $d */
    public function informarPago(object $toma, User $comprador, array $d): void
    {
        $this->transicion($toma, ['reservada'], 'pago_informado', 'pago_informado', array_filter($d), $comprador->id, 'comprador', function ($c, $t) {
            app(Notificador::class)->aRoles($c->establecimiento_id, ['dueno', 'admin'], 'pago_informado', "Informaron el pago del anticipo ({$c->codigo_publico})",
                'Confirmá «recibí» cuando lo veas en tu cuenta.', ['contrato_id' => $c->id, 'toma_id' => $t->id], "toma:{$t->id}:pago");
        });
    }

    public function confirmarPago(object $toma, User $productor): void
    {
        $this->transicion($toma, ['reservada', 'pago_informado'], 'confirmada', 'confirmada', [], $productor->id, 'productor', null, ['vence_en' => null]);
    }

    /** @param array{referencia?: ?string, fecha_pago?: ?string} $d */
    public function devolucionInformada(object $toma, User $productor, array $d): void
    {
        if (D::cmp((string) $toma->anticipo_a_devolver, '0') <= 0) {
            throw ApiException::reglaBloqueante(__('Esta toma no tiene anticipo a devolver.'));
        }
        $this->transicion($toma, ['confirmada', 'liberada'], null, 'devolucion_informada', array_filter($d) + ['monto' => (string) $toma->anticipo_a_devolver], $productor->id, 'productor');
    }

    public function devolucionConfirmada(object $toma, User $comprador): void
    {
        if (! DB::table('toma_movimientos')->where('toma_id', $toma->id)->where('tipo', 'devolucion_informada')->exists()) {
            throw ApiException::reglaBloqueante(__('El productor todavía no informó la devolución.'));
        }
        $this->transicion($toma, ['confirmada', 'liberada'], $toma->estado === 'confirmada' ? 'devuelta_parcial' : null, 'devolucion_confirmada',
            ['monto' => (string) $toma->anticipo_a_devolver], $comprador->id, 'comprador');
    }

    /** Job (TPA-57): reservas sin «recibí» vencidas → liberadas (movimiento del sistema); los tokens vuelven a estar libres. */
    public function liberarVencidas(): int
    {
        $n = 0;
        foreach (DB::table('tomas')->whereIn('estado', ['reservada', 'pago_informado'])->where('vence_en', '<', now())->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$n) {
                $t = DB::table('tomas')->where('id', $id)->first();
                $c = DB::table('contratos_futuro')->where('id', $t->contrato_id)->lockForUpdate()->first();
                $t = DB::table('tomas')->where('id', $id)->lockForUpdate()->first();
                if (! in_array($t->estado, ['reservada', 'pago_informado'], true) || Carbon::parse($t->vence_en)->isFuture()) {
                    return;
                }
                DB::table('tomas')->where('id', $id)->update(['estado' => 'liberada', 'tokens_vigentes' => 0, 'vence_en' => null, 'updated_at' => now()]);
                $this->movimiento(Cadena::bloquearDeEstablecimiento($c->establecimiento_id), $id, 'liberada', ['tokens' => (int) $t->tokens_vigentes, 'motivo' => 'vencimiento'], null, 'sistema');
                $this->recalcularContrato($c->id);
                app(Notificador::class)->aUsuario($t->comprador_id, null, 'toma_liberada', "Se liberó tu reserva del contrato {$c->codigo_publico}",
                    'Pasaron '.$c->toma_vencimiento_horas.' h sin confirmación del anticipo.', ['toma_id' => $id], "toma:{$id}:liberada");
                $n++;
            });
        }

        return $n;
    }

    /**
     * Aplica un recorte (mortalidad/faltante): libera tomas no confirmadas y recorta prorrata las confirmadas, con el anticipo a devolver.
     * Se llama dentro de la transacción que bloqueó el contrato.
     *
     * @param  array{liberar: list<string>, recortes: list<array{toma_id: string, de: int, a: int}>}  $plan
     */
    public function aplicarRecorte(object $c, Cadena $cadena, array $plan, User $productor, string $motivo): void
    {
        foreach ($plan['liberar'] as $id) {
            $t = DB::table('tomas')->where('id', $id)->first();
            DB::table('tomas')->where('id', $id)->update(['estado' => 'liberada', 'tokens_vigentes' => 0, 'vence_en' => null, 'updated_at' => now()]);
            $this->movimiento($cadena, $id, 'liberada', ['tokens' => (int) $t->tokens_vigentes, 'motivo' => $motivo], $productor->id, 'productor');
        }
        foreach ($plan['recortes'] as $r) {
            $t = DB::table('tomas')->where('id', $r['toma_id'])->first();
            $devolver = D::redondear(D::sum((string) $t->anticipo_a_devolver, D::mul((string) ($r['de'] - $r['a']), (string) $c->anticipo_por_token)), 2);
            DB::table('tomas')->where('id', $t->id)->update(['tokens_vigentes' => $r['a'], 'kg' => $r['a'] * $c->kg_por_token, 'anticipo_a_devolver' => $devolver, 'updated_at' => now()]);
            $this->movimiento($cadena, $t->id, 'recorte_prorrata', ['tokens_antes' => $r['de'], 'tokens' => $r['a'], 'anticipo_a_devolver' => $devolver, 'motivo' => $motivo], $productor->id, 'productor');
            app(Notificador::class)->aUsuario($t->comprador_id, null, 'recorte_prorrata', "Recorte en el contrato {$c->codigo_publico}",
                "Tus tokens pasan de {$r['de']} a {$r['a']}; el productor te devuelve {$c->moneda} {$devolver} de anticipo.", ['toma_id' => $t->id], "toma:{$t->id}:recorte:".now()->timestamp);
        }
    }

    /**
     * @param  list<string>  $desde
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $extra
     */
    private function transicion(object $toma, array $desde, ?string $hacia, string $tipo, array $datos, ?string $actorId, string $rol, ?\Closure $despues = null, array $extra = []): void
    {
        DB::transaction(function () use ($toma, $desde, $hacia, $tipo, $datos, $actorId, $rol, $despues, $extra) {
            $c = DB::table('contratos_futuro')->where('id', $toma->contrato_id)->lockForUpdate()->first();
            $t = DB::table('tomas')->where('id', $toma->id)->lockForUpdate()->first();
            if (! in_array($t->estado, $desde, true)) {
                throw ApiException::reglaBloqueante(__('La toma está en estado :e.', ['e' => $t->estado]));
            }
            if ($hacia !== null) {
                DB::table('tomas')->where('id', $t->id)->update(['estado' => $hacia, 'updated_at' => now(), ...$extra]);
            }
            $this->movimiento(Cadena::bloquearDeEstablecimiento($c->establecimiento_id), $t->id, $tipo, $datos, $actorId, $rol);
            $this->recalcularContrato($c->id);
            if ($despues) {
                $despues($c, $t);
            }
        });
    }

    /** @param array<string, mixed> $datos */
    public function movimiento(Cadena $cadena, string $tomaId, string $tipo, array $datos, ?string $actorId, string $rol): void
    {
        $t = DB::table('tomas')->where('id', $tomaId)->first();
        $huellaContrato = DB::table('contrato_versiones')->where('contrato_id', $t->contrato_id)->where('version', $t->contrato_version)->value('hash');
        $r = Sobres::registrar($cadena, 'tropilla.toma_movimiento/1', 'toma_movimiento', [
            'id' => (string) Str::uuid7(), 'toma_id' => $tomaId, 'contrato_id' => $t->contrato_id, 'contrato_version_huella' => $huellaContrato, 'tipo' => $tipo,
            'tokens' => (int) $t->tokens_vigentes, 'kg' => (int) $t->kg, 'datos' => (object) array_map(fn ($v) => is_float($v) ? (string) $v : $v, $datos),
            'actor_id' => $actorId, 'rol_actor' => $rol,
        ]);
        DB::table('toma_movimientos')->insert(['id' => $r['sobre']->id, 'toma_id' => $tomaId, 'tipo' => $tipo, 'datos' => json_encode((object) $datos), 'actor_id' => $actorId,
            'rol_actor' => $rol, 'sobre' => Sobres::json($r['sobre']), 'hash' => $r['huella'], 'registro_integridad_id' => $r['registro_id'], 'created_at' => now()]);
    }

    /** Proyección: tokens comprometidos y estado (publicado / parcialmente_tomado / completo). */
    public function recalcularContrato(string $contratoId): void
    {
        $c = DB::table('contratos_futuro')->where('id', $contratoId)->first();
        $comp = (int) DB::table('tomas')->where('contrato_id', $contratoId)->whereIn('estado', self::ACTIVAS)->sum('tokens_vigentes');
        $estado = $c->estado;
        if (in_array($c->estado, ['publicado', 'parcialmente_tomado', 'completo'], true)) {
            $estado = $comp === 0 ? 'publicado' : ($comp >= $c->tokens_totales ? 'completo' : 'parcialmente_tomado');
        }
        DB::table('contratos_futuro')->where('id', $contratoId)->update(['tokens_comprometidos' => $comp, 'estado' => $estado, 'updated_at' => now()]);
    }
}
