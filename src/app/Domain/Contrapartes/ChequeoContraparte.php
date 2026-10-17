<?php

namespace App\Domain\Contrapartes;

use App\Domain\Contrapartes\Fuentes\ArcaConstanciaInscripcion;
use App\Domain\Contrapartes\Fuentes\BcraCentralDeudores;
use App\Domain\Contrapartes\Fuentes\FuenteContraparte;

/**
 * Chequeo de contraparte por CUIT (TPA-72). Reúne lo que informan fuentes públicas permitidas, cada una con su estado, fecha y
 * enlace, y lo traduce a frases neutrales ("figura X informado por Y al período Z"). **No** calcula score, no clasifica a la
 * contraparte, no bloquea operaciones y no usa la antigüedad del CUIT como señal (desactivada hasta validación legal/predictiva).
 */
final class ChequeoContraparte
{
    public const FINALIDADES = [
        'venta_contado' => 'Venta de hacienda al contado',
        'venta_plazo' => 'Venta de hacienda a plazo',
        'cobro_cheque' => 'Cobro con cheque o e-cheq',
        'contrato_futuro' => 'Contrato a futuro',
        'compra' => 'Compra de hacienda o insumos',
        'otra' => 'Otra operación comercial',
    ];

    public const CHECKLIST = [
        'identidad' => 'La denominación informada coincide con la razón social o el nombre de tu contraparte (cuidado con homónimos).',
        'constancia_arca' => 'Viste la constancia de inscripción en ARCA y coinciden CUIT, nombre y actividad.',
        'ruca' => 'Si opera como consignatario, matarife, frigorífico u otro operador de la cadena, verificaste su inscripción vigente en el RUCA.',
        'referencias' => 'Pediste referencias comerciales (otros productores, consignataria o banco).',
        'condiciones' => 'Acordaste por escrito precio, plazo, forma de pago y quién paga flete y comisiones.',
        'garantias' => 'Si el pago es a plazo o con cheque, evaluaste pedir anticipo, aval o cheque de una entidad.',
        'cheques_denunciados' => 'Si recibís cheques, consultaste por número que no estén denunciados (BCRA, cheques denunciados).',
    ];

    public const AVISOS = [
        'Son datos informados por terceros a la fecha indicada en cada fuente. TROPILLA no califica ni recomienda operar o no operar.',
        'Que una fuente no tenga información no significa que no haya deudas ni juicios: cada fuente cubre solo lo que le informan.',
        'Verificá que la denominación coincida con tu contraparte: puede haber homónimos o errores de carga del CUIT.',
        'Si sos el titular y un dato es incorrecto, el reclamo se hace ante la entidad que lo informó; la fuente publica lo que recibe.',
        'Cheques denunciados no se consultan por CUIT en la API del BCRA: verificá cada cheque por banco y número antes de aceptar el documento.',
    ];

    /** @return list<FuenteContraparte> */
    public function fuentes(): array
    {
        return [app(BcraCentralDeudores::class), app(ArcaConstanciaInscripcion::class)];
    }

    /** @return array<string, mixed> Informe con fuentes, datos, lectura neutral y avisos. */
    public function consultar(string $cuit): array
    {
        $resultados = array_map(fn (FuenteContraparte $f) => $f->consultar($cuit), $this->fuentes());
        $bcra = collect($resultados)->firstWhere('codigo', 'bcra_central_deudores');
        $datos = $bcra['estado'] === 'ok' ? $bcra['datos'] : null;

        return [
            'cuit' => Cuit::formatear($cuit),
            'tipo_persona' => Cuit::tipoPersona($cuit),
            'denominacion' => $datos['denominacion'] ?? null,
            'fuentes' => array_map(fn ($r) => array_diff_key($r, ['datos' => true]), $resultados),
            'bcra' => $datos,
            'lectura' => $this->lectura($bcra),
            'avisos' => self::AVISOS,
            'senales_desactivadas' => ['Antigüedad del CUIT: no se muestra. No hay una fuente oficial abierta que la informe ni evidencia de que anticipe incumplimientos.'],
        ];
    }

    /**
     * Frases neutrales con fuente y período. Nunca adjetivan a la contraparte.
     *
     * @param  array<string, mixed>  $bcra
     * @return list<string>
     */
    private function lectura(array $bcra): array
    {
        if ($bcra['estado'] === 'error') {
            return ['No pudimos consultar al BCRA en este momento. Esto no dice nada sobre la contraparte: probá de nuevo más tarde.'];
        }
        if ($bcra['estado'] === 'no_configurada') {
            return ['La consulta al BCRA no está disponible en este entorno.'];
        }
        if ($bcra['estado'] === 'sin_datos') {
            return ['El BCRA no tiene registros informados por entidades financieras para este CUIT. No significa que no tenga deudas comerciales, con proveedores o juicios.'];
        }
        $d = $bcra['datos'];
        $l = [];
        if ($a = $d['actual']) {
            $n = count($a['entidades']);
            $l[] = "Al período {$a['periodo']}, ".($n === 1 ? '1 entidad informa' : "{$n} entidades informan").' deudas por un total de $ '.self::miles($a['monto_total_miles'])
                .' mil. Situación más alta informada: '.(BcraCentralDeudores::SITUACIONES[$a['peor_situacion']] ?? 'sin dato').'.';
            foreach ($a['entidades'] as $e) {
                if ($e['situacion'] >= 2) {
                    $l[] = "{$e['entidad']} informa {$e['situacion_descripcion']}".($e['dias_atraso'] ? " con {$e['dias_atraso']} días de atraso" : '').'.';
                }
                if ($e['proceso_judicial']) {
                    $l[] = "{$e['entidad']} indica que la deuda está en proceso judicial.";
                }
                if ($e['en_revision']) {
                    $l[] = "El dato de {$e['entidad']} figura en revisión.";
                }
            }
        } else {
            $l[] = 'No figuran deudas informadas en el último período publicado.';
        }
        if ($h = $d['historial']) {
            $l[] = 'En los últimos '.count($h['periodos']).' períodos publicados, '.($h['meses_con_situacion_2_o_mas'] === 0
                ? 'no hay meses con situación 2 o superior.'
                : "hay {$h['meses_con_situacion_2_o_mas']} con situación 2 o superior en alguna entidad.");
        }
        $c = $d['cheques'];
        $l[] = $c === null || $c['total'] === 0
            ? 'No figuran cheques rechazados informados al BCRA.'
            : "Figuran {$c['total']} cheques rechazados informados al BCRA ({$c['sin_pago_informado']} sin pago informado). Rechazado no es lo mismo que denunciado.";

        return $l;
    }

    private static function miles(float $v): string
    {
        return number_format($v, 0, ',', '.');
    }
}
