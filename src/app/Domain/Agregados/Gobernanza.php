<?php

namespace App\Domain\Agregados;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reglas de acceso y gate del tablero agregado (TPA-75).
 * - Solo el equipo (`es_staff`) administra política, clientes, pruebas y revisiones; para cualquier otro esas rutas no existen (404).
 * - Un cliente institucional consulta solo con membresía activa, licencia activa y vigente, y solo las métricas licenciadas.
 * - El gate habilita **planificar** el opt-in real recién cuando los cuatro aspectos están aprobados bajo la política vigente
 *   (reidentificación exige una prueba aprobada de esa versión). Mientras tanto, la única fuente es el dataset sintético.
 */
final class Gobernanza
{
    public const ASPECTOS = ['reidentificacion', 'permisos', 'cohortes', 'auditoria'];

    public const TIPOS_CLIENTE = ['frigorifico', 'consignataria', 'banco', 'aseguradora', 'gobierno', 'proveedor', 'asociacion', 'otro'];

    public const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

    public static function politica(): object
    {
        return DB::table('agregados_politicas')->orderByDesc('version')->first();
    }

    public static function soloStaff(User $u): void
    {
        $u->es_staff || throw ApiException::noEncontrado();
    }

    /** Licencia que habilita la consulta (o null para la vista previa del equipo). */
    public static function licencia(User $u, ?string $clienteId, string $metrica): ?object
    {
        if ($clienteId === null) {
            $u->es_staff || throw ApiException::validacion(__('Elegí la licencia con la que consultás.'), ['cliente' => [__('Obligatorio.')]]);

            return null;
        }
        $c = self::cliente($clienteId);
        $u->es_staff || self::esMiembro($u, $c->id) || throw ApiException::noEncontrado();
        $c->estado === 'activo' || throw new ApiException(403, 'licencia_suspendida', __('La licencia de :n está suspendida.', ['n' => $c->nombre]));
        ($c->vigente_hasta === null || Carbon::parse($c->vigente_hasta)->endOfDay()->isFuture())
            || throw new ApiException(403, 'licencia_vencida', __('La licencia de :n venció el :f.', ['n' => $c->nombre, 'f' => Carbon::parse($c->vigente_hasta)->format('d/m/Y')]));
        in_array($metrica, self::metricas($c), true)
            || throw new ApiException(403, 'metrica_no_habilitada', __('La licencia no incluye esta métrica.'));

        return $c;
    }

    public static function cliente(string $id): object
    {
        return (Str::isUuid($id) ? DB::table('agregados_clientes')->where('id', strtolower($id))->first() : null) ?? throw ApiException::noEncontrado();
    }

    public static function esMiembro(User $u, string $clienteId): bool
    {
        return DB::table('agregados_cliente_miembros')->where('cliente_id', $clienteId)->where('usuario_id', $u->id)->where('estado', 'activa')->exists();
    }

    /** @return list<string> */
    public static function metricas(object $c): array
    {
        $m = trim((string) $c->metricas, '{}');

        return $m === '' ? [] : array_values(array_intersect(MotorAgregados::METRICAS, explode(',', $m)));
    }

    public static function cupoDiario(?object $cliente, object $politica): int
    {
        return min($politica->consultas_por_dia, $cliente?->consultas_por_dia ?? PHP_INT_MAX);
    }

    public static function consultasHoy(string $clienteId): int
    {
        return DB::table('agregados_consultas')->where('cliente_id', $clienteId)
            ->where('created_at', '>=', Carbon::now(self::ZONA_HORARIA)->startOfDay()->utc())->count();
    }

    /** @return array{aspectos: list<array<string, mixed>>, habilita_opt_in_real: bool, politica_version: int} */
    public static function gate(object $politica): array
    {
        $aspectos = [];
        foreach (self::ASPECTOS as $a) {
            $r = DB::table('agregados_revisiones as r')->join('usuarios as u', 'u.id', '=', 'r.revisor_id')
                ->where('r.aspecto', $a)->where('r.politica_version', $politica->version)
                ->orderByDesc('r.created_at')->orderByDesc('r.id')->first(['r.*', 'u.nombre as revisor']);
            $aspectos[] = ['aspecto' => $a, 'estado' => $r->resultado ?? 'pendiente', 'nota' => $r->nota ?? null, 'revisor' => $r->revisor ?? null,
                'prueba_id' => $r->prueba_id ?? null, 'revisado_en' => $r ? Formato::fechaHora(Carbon::parse($r->created_at)) : null];
        }

        return ['aspectos' => $aspectos, 'habilita_opt_in_real' => collect($aspectos)->every(fn ($x) => $x['estado'] === 'aprobado'),
            'politica_version' => (int) $politica->version];
    }
}
