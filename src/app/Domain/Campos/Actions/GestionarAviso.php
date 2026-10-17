<?php

namespace App\Domain\Campos\Actions;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta, edición y ciclo de vida de un aviso de campo (TPA-74), con auditoría y `row_version`.
 * Estados: borrador → publicado ⇄ pausado → cerrado; `en_revision` lo pone la moderación (disputas) y solo se sale por resolución.
 * Si cambian datos que se verificaron (modalidad, capacidad, especies, zona), el nivel de verificación vuelve a «declarado».
 */
final class GestionarAviso
{
    private const SUSTANCIALES = ['modalidad', 'capacidad_cabezas', 'especies', 'provincia_codigo', 'departamento_codigo', 'superficie_ha'];

    public const MOTIVOS_CIERRE = ['acuerdo_tropilla' => 'Se acordó con un contacto de TROPILLA', 'acuerdo_otro_medio' => 'Se acordó por otro medio',
        'no_disponible' => 'El campo ya no está disponible', 'otro' => 'Otro motivo'];

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array<string, mixed> $d */
    public function crear(Establecimiento $est, User $u, array $d): object
    {
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($est, $u, $d, $id) {
            $fila = $this->fila($d) + ['provincia_codigo' => $est->provincia_codigo, 'departamento_codigo' => $est->departamento_codigo, 'localidad' => $est->localidad];
            DB::table('campo_avisos')->insert(['id' => $id, 'establecimiento_id' => $est->id, 'autor_id' => $u->id, ...$fila,
                'estado' => 'borrador', 'created_at' => now(), 'updated_at' => now()]);
            $this->auditoria->handle($est->id, $u->id, 'campo_aviso', $id, 'crear', null, $this->auditable($id));
        });

        return DB::table('campo_avisos')->where('id', $id)->first();
    }

    /** @param array<string, mixed> $d */
    public function editar(Establecimiento $est, User $u, object $aviso, array $d): object
    {
        return DB::transaction(function () use ($est, $u, $aviso, $d) {
            $actual = DB::table('campo_avisos')->where('id', $aviso->id)->lockForUpdate()->first();
            if ((int) $actual->row_version !== (int) $d['row_version']) {
                throw ApiException::conflictoRowVersion(['row_version' => (int) $actual->row_version]);
            }
            if ($actual->estado === 'cerrado') {
                throw ApiException::reglaBloqueante('El aviso está cerrado: publicá uno nuevo.');
            }
            $antes = $this->auditable($actual->id);
            $fila = $this->fila($d);
            $cambiaVerificado = $actual->verificacion !== 'declarado' && collect(self::SUSTANCIALES)
                ->contains(fn ($k) => array_key_exists($k, $fila) && self::normal($k, $fila[$k]) !== self::normal($k, $actual->{$k}));
            if ($cambiaVerificado) {
                $fila += ['verificacion' => 'declarado', 'verificacion_nota' => 'Volvió a «declarado» porque cambiaron datos verificados.', 'verificado_por' => null, 'verificado_en' => null];
            }
            DB::table('campo_avisos')->where('id', $actual->id)->update([...$fila, 'row_version' => $actual->row_version + 1, 'updated_at' => now()]);
            $this->auditoria->handle($est->id, $u->id, 'campo_aviso', $actual->id, 'editar', $antes, $this->auditable($actual->id));

            return DB::table('campo_avisos')->where('id', $actual->id)->first();
        });
    }

    public function cambiarEstado(Establecimiento $est, User $u, object $aviso, string $accion, ?string $motivo = null): object
    {
        return DB::transaction(function () use ($est, $u, $aviso, $accion, $motivo) {
            $a = DB::table('campo_avisos')->where('id', $aviso->id)->lockForUpdate()->first();
            $nuevo = match ($accion) {
                'publicar' => in_array($a->estado, ['borrador', 'pausado'], true) ? 'publicado' : null,
                'pausar' => $a->estado === 'publicado' ? 'pausado' : null,
                'cerrar' => $a->estado !== 'cerrado' ? 'cerrado' : null,
            };
            if ($nuevo === null) {
                throw ApiException::reglaBloqueante($a->estado === 'en_revision'
                    ? 'El aviso está en revisión por un reporte. El equipo de TROPILLA te va a contactar.'
                    : "No se puede {$accion} un aviso {$a->estado}.");
            }
            if ($nuevo === 'publicado') {
                $u->email_verificado_en || throw new ApiException(403, 'email_no_verificado', 'Verificá tu email para publicar.');
                if ($a->temporada_hasta !== null && $a->temporada_hasta < now()->toDateString()) {
                    throw ApiException::reglaBloqueante('La temporada ya terminó: actualizá las fechas antes de publicar.', ['temporada_hasta' => ['La temporada ya terminó.']]);
                }
            }
            $fila = ['estado' => $nuevo, 'row_version' => $a->row_version + 1, 'updated_at' => now()];
            if ($nuevo === 'publicado' && $a->publicado_en === null) {
                $fila['publicado_en'] = now();
            }
            if ($nuevo === 'cerrado') {
                $fila += ['cerrado_en' => now(), 'motivo_cierre' => $motivo];
            }
            DB::table('campo_avisos')->where('id', $a->id)->update($fila);
            $this->auditoria->handle($est->id, $u->id, 'campo_aviso', $a->id, $accion, ['estado' => $a->estado], ['estado' => $nuevo, 'motivo_cierre' => $motivo]);

            return DB::table('campo_avisos')->where('id', $a->id)->first();
        });
    }

    private static function normal(string $campo, mixed $v): string
    {
        return match ($campo) {
            'especies' => implode(',', collect(PgTextArray::parse($v))->sort()->values()->all()),
            'superficie_ha' => (string) Formato::decimal($v),
            default => (string) $v,
        };
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function fila(array $d): array
    {
        $f = [];
        foreach (['titulo', 'descripcion', 'modalidad', 'capacidad_cabezas', 'superficie_ha', 'temporada_desde', 'temporada_hasta', 'plazo_minimo_meses',
            'precio_unidad', 'precio_valor', 'condiciones', 'alambrado', 'accesos', 'provincia_codigo', 'departamento_codigo', 'localidad', 'mostrar_localidad'] as $k) {
            if (array_key_exists($k, $d)) {
                $f[$k] = $d[$k];
            }
        }
        if (($d['precio_unidad'] ?? null) === 'a_convenir') {
            $f['precio_valor'] = null;
        }
        foreach (['especies', 'categorias', 'agua', 'instalaciones'] as $k) {
            if (array_key_exists($k, $d)) {
                $f[$k] = PgTextArray::literal(array_values(array_unique((array) $d[$k])));
            }
        }

        return $f;
    }

    /** @return array<string, mixed> */
    private function auditable(string $id): array
    {
        $a = (array) DB::table('campo_avisos')->where('id', $id)->first();

        return array_intersect_key($a, array_flip(['titulo', 'modalidad', 'capacidad_cabezas', 'superficie_ha', 'temporada_desde', 'temporada_hasta',
            'precio_unidad', 'precio_valor', 'provincia_codigo', 'departamento_codigo', 'estado', 'verificacion', 'especies']));
    }
}
