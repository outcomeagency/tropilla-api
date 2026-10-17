<?php

namespace App\Domain\Sanidad\Oficial;

use App\Domain\Catalogos\Parametros;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reglas comunes de pruebas, estatus y saneamiento de brucelosis/TBC (TPA-66). TROPILLA guarda **transcripciones** o
 * declaraciones del productor: la condición oficial la certifica SENASA (SIGSA / Oficina Local); por eso cada dato lleva
 * su origen y el aviso que lo aclara.
 */
final class SanidadOficial
{
    public const ENFERMEDADES = ['brucelosis', 'tuberculosis'];

    public const MOTIVOS = ['does', 'recertificacion', 'saneamiento', 'movimiento', 'rutina', 'otro'];

    public const RESULTADOS = ['negativo', 'sospechoso', 'positivo', 'no_concluyente', 'no_apto'];

    public const ESTATUS = ['libre', 'en_saneamiento', 'en_certificacion', 'sin_estatus', 'suspendido'];

    public const ORIGENES = ['declarado', 'transcripcion_revisada'];

    public const AVISO = 'Transcripción o declaración del productor: no es la condición sanitaria oficial. La certifica SENASA (SIGSA / Oficina Local).';

    /** Leyenda por origen para mostrar al lado del dato. */
    public static function leyendaOrigen(string $origen): string
    {
        return $origen === 'transcripcion_revisada'
            ? __('Transcripción revisada del original adjunto')
            : __('Declarado por el productor, sin original adjunto');
    }

    /** Pruebas del catálogo para la enfermedad. @return list<string> */
    public static function pruebas(string $enfermedad): array
    {
        return DB::table('tipos_prueba')->where('enfermedad', $enfermedad)->orderBy('id')->pluck('codigo')->all();
    }

    /** Vencimiento por defecto de un muestreo para movimiento (seronegatividad de brucelosis, parámetro por establecimiento). */
    public static function venceMovimiento(string $establecimientoId, string $enfermedad, string $motivo, string $fechaMuestreo): ?string
    {
        if ($motivo !== 'movimiento' || $enfermedad !== 'brucelosis') {
            return null;
        }
        $dias = (int) (Parametros::valor('brucelosis_seronegatividad_validez_dias', establecimientoId: $establecimientoId) ?? 60);

        return Carbon::parse($fechaMuestreo)->addDays($dias)->toDateString();
    }

    public static function diasAviso(string $establecimientoId): int
    {
        return (int) (Parametros::valor('sanidad_oficial_aviso_dias', establecimientoId: $establecimientoId) ?? 30);
    }

    /**
     * Animal de TROPILLA para una identificación transcripta (caravana, RFID, tatuaje…), tolerante a ceros a la izquierda.
     * Devuelve null si no hay coincidencia o si es ambigua (dos animales con el mismo número).
     */
    public static function animalPorIdentificacion(string $establecimientoId, string $valor): ?string
    {
        $norm = Identificaciones::normalizar($valor);
        if ($norm === '') {
            return null;
        }
        $ids = DB::table('animal_identificaciones')->where('establecimiento_id', $establecimientoId)->where('vigente', true)
            ->whereRaw(Identificaciones::SQL_SIN_CEROS.' = ?', [Identificaciones::sinCeros($norm)])->distinct()->limit(2)->pluck('animal_id')->all();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** vigente | por_vencer | vencido | sin_vencimiento */
    public static function vigencia(?string $venceEl, string $hoy, int $diasAviso): string
    {
        return match (true) {
            $venceEl === null => 'sin_vencimiento',
            $venceEl < $hoy => 'vencido',
            $venceEl <= Carbon::parse($hoy)->addDays($diasAviso)->toDateString() => 'por_vencer',
            default => 'vigente',
        };
    }
}
