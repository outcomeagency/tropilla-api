<?php

namespace App\Domain\Contratos\Actions;

use App\Domain\Comercial\Actions\RegistrarOperacion;
use App\Domain\Contratos\Sobres;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Decimales as D;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entrega y liquidación (TPA-56/57, §9.5). El productor carga peso y rendimiento reales → factor = kg gancho reales / contratados;
 * dentro de la tolerancia (±5 %) se liquida por los reales: por toma, kg efectivos = kg × factor, importe = kg efectivos × precio
 * especial, saldo = importe − anticipo neto. Fuera de tolerancia → `a_resolver` (la app solo registra). Confirma el productor y
 * cada comprador; con todas las confirmaciones: sobre `tropilla.entrega/1` hasheado, operación comercial (venta al gancho, PDF a
 * Documentos) y contrato `liquidado`.
 */
final class GestionarEntrega
{
    public function __construct(private readonly GestionarTomas $tomas) {}

    /** @param array{cabezas_entregadas: int, peso_vivo_total_kg: string, rendimiento_real_pct: string, kg_gancho_reales?: ?int} $d */
    public function cargar(Establecimiento $est, object $c, array $d): void
    {
        if (! in_array($c->estado, ['publicado', 'parcialmente_tomado', 'completo', 'entregado'], true)) {
            throw ApiException::reglaBloqueante(__('El contrato no está en condiciones de entregarse.'));
        }
        $e = DB::table('entregas')->where('contrato_id', $c->id)->first();
        if ($e && $e->estado === 'confirmada') {
            throw ApiException::reglaBloqueante(__('La entrega ya está liquidada.'));
        }
        $t = json_decode($c->terminos_borrador, true);
        $reales = $d['kg_gancho_reales'] ?? (int) bcdiv(bcmul($d['peso_vivo_total_kg'], $d['rendimiento_real_pct'], 10), '100', 0);
        $factor = D::div((string) $reales, (string) $t['kg_gancho_totales']);
        $dentro = D::cmp(D::sub($factor, '1'), D::div($t['tolerancia_pct'], '100')) <= 0 && D::cmp(D::sub('1', $factor), D::div($t['tolerancia_pct'], '100')) <= 0;
        $liq = [];
        foreach (DB::table('tomas')->where('contrato_id', $c->id)->whereIn('estado', GestionarTomas::CONFIRMADAS)->where('tokens_vigentes', '>', 0)->orderBy('created_at')->get() as $tm) {
            $kgEf = D::redondear(D::mul((string) ($tm->tokens_vigentes * $c->kg_por_token), $factor), 1);
            $importe = D::redondear(D::mul($kgEf, (string) $c->precio_especial_kg), 2);
            $anticipo = D::redondear(D::sub((string) $tm->anticipo_monto, (string) $tm->anticipo_a_devolver), 2);
            $liq[] = ['toma_id' => $tm->id, 'comprador_id' => $tm->comprador_id, 'kg_efectivos' => $kgEf, 'importe' => $importe, 'anticipo' => $anticipo,
                'saldo' => D::redondear(D::sub($importe, $anticipo), 2)];
        }
        $fila = ['establecimiento_id' => $est->id, 'cabezas_entregadas' => $d['cabezas_entregadas'], 'peso_vivo_total_kg' => $d['peso_vivo_total_kg'],
            'rendimiento_real_pct' => $d['rendimiento_real_pct'], 'kg_gancho_reales' => $reales, 'factor' => D::redondear($factor, 6), 'dentro_tolerancia' => $dentro,
            'liquidacion' => json_encode($liq), 'estado' => $dentro ? 'cargada' : 'a_resolver', 'confirmaciones' => '[]', 'updated_at' => now()];
        $e ? DB::table('entregas')->where('id', $e->id)->update($fila)
            : DB::table('entregas')->insert($fila + ['id' => (string) Str::uuid7(), 'contrato_id' => $c->id, 'created_at' => now()]);
    }

    public function confirmar(Establecimiento $est, object $c, User $u, string $rol): void
    {
        DB::transaction(function () use ($est, $c, $u, $rol) {
            $cadena = Cadena::bloquearDeEstablecimiento($c->establecimiento_id);
            $e = DB::table('entregas')->where('contrato_id', $c->id)->lockForUpdate()->first() ?? throw ApiException::noEncontrado(__('Todavía no hay entrega cargada.'));
            if ($e->estado === 'a_resolver') {
                throw ApiException::reglaBloqueante(__('Fuera de tolerancia: queda a resolver entre las partes.'));
            }
            if ($e->estado === 'confirmada') {
                return;
            }
            $liq = json_decode($e->liquidacion, true);
            if ($rol === 'comprador' && ! in_array($u->id, array_column($liq, 'comprador_id'), true)) {
                throw ApiException::noEncontrado();
            }
            if ($rol === 'comprador' && $e->estado !== 'confirmada_productor') {
                throw ApiException::reglaBloqueante(__('Primero confirma el productor.'));
            }
            $conf = json_decode($e->confirmaciones, true);
            if (! collect($conf)->contains(fn ($x) => $x['usuario_id'] === $u->id && $x['rol'] === $rol)) {
                $conf[] = ['rol' => $rol, 'usuario_id' => $u->id, 'en' => Formato::fechaHora(now())];
            }
            $compradores = array_values(array_unique(array_column($liq, 'comprador_id')));
            $confirmaron = array_column(array_filter($conf, fn ($x) => $x['rol'] === 'comprador'), 'usuario_id');
            $completa = collect($conf)->contains(fn ($x) => $x['rol'] === 'productor') && array_diff($compradores, $confirmaron) === [];
            $estado = $completa ? 'confirmada' : 'confirmada_productor';
            DB::table('entregas')->where('id', $e->id)->update(['confirmaciones' => json_encode($conf), 'estado' => $estado, 'updated_at' => now()]);
            DB::table('contratos_futuro')->where('id', $c->id)->update(['estado' => $completa ? 'liquidado' : 'entregado', 'updated_at' => now()]);
            if ($rol === 'productor') {
                foreach ($compradores as $comp) {
                    app(Notificador::class)->aUsuario($comp, null, 'entrega_liquidada', "Entrega del contrato {$c->codigo_publico}", 'Revisá la liquidación y confirmala.',
                        ['contrato_id' => $c->id], "entrega:{$c->id}:productor");
                }
            }
            if ($completa) {
                $this->liquidar($est, $c, $e, $liq, $conf, $cadena, $u);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $liq
     * @param  list<array<string, mixed>>  $conf
     */
    private function liquidar(Establecimiento $est, object $c, object $e, array $liq, array $conf, Cadena $cadena, User $u): void
    {
        $r = Sobres::registrar($cadena, 'tropilla.entrega/1', 'entrega', [
            'id' => $e->id, 'contrato_id' => $c->id, 'establecimiento_id' => $c->establecimiento_id, 'cabezas_entregadas' => (int) $e->cabezas_entregadas,
            'peso_real_kg' => (string) Formato::decimal($e->peso_vivo_total_kg), 'rendimiento_pct' => (string) Formato::decimal($e->rendimiento_real_pct),
            'kg_efectivos' => (int) $e->kg_gancho_reales, 'factor' => (string) Formato::decimal($e->factor),
            'saldos' => array_map(fn ($l) => ['toma_id' => $l['toma_id'], 'kg_efectivos' => $l['kg_efectivos'], 'importe' => $l['importe'], 'anticipo' => $l['anticipo'], 'saldo' => $l['saldo']], $liq),
            'confirmaciones' => $conf,
        ]);
        foreach ($liq as $l) {
            $this->tomas->movimiento($cadena, $l['toma_id'], 'entrega_liquidada', ['kg_efectivos' => $l['kg_efectivos'], 'saldo' => $l['saldo']], $u->id, 'sistema');
        }
        $productor = DB::table('membresias')->where('establecimiento_id', $est->id)->where('estado', 'activa')->where('rol', 'dueno')->value('usuario_id');
        $opId = app(RegistrarOperacion::class)->alta($est, User::query()->findOrFail($productor), [
            'tipo' => 'venta', 'fecha' => now($est->zona_horaria)->toDateString(), 'especie' => $c->especie_codigo, 'categoria' => $c->categoria_codigo,
            'cantidad' => (int) $e->cabezas_entregadas, 'contrato_id' => $c->id, 'confirmar_advertencias' => true,
            'animal_ids' => DB::table('contrato_animales')->where('contrato_id', $c->id)->pluck('animal_id')->all(),
            'trato' => ['modalidad' => 'al_gancho', 'comprador' => 'Contrato a futuro '.$c->codigo_publico.' ('.count($liq).' comprador/es)', 'tolerancias' => '±'.Formato::decimal($c->tolerancia_pct).' %'],
            'liquidacion' => ['peso_bruto_kg' => (string) Formato::decimal($e->peso_vivo_total_kg), 'rendimiento_pct' => (string) Formato::decimal($e->rendimiento_real_pct),
                'precio_kg' => (string) Formato::decimal($c->precio_especial_kg), 'moneda' => $c->moneda],
        ]);
        DB::table('entregas')->where('id', $e->id)->update(['sobre' => Sobres::json($r['sobre']), 'hash' => $r['huella'], 'registro_integridad_id' => $r['registro_id'],
            'operacion_id' => $opId, 'documento_id' => DB::table('operaciones')->where('id', $opId)->value('documento_id'), 'updated_at' => now()]);
    }
}
