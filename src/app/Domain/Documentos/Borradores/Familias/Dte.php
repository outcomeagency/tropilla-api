<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;

/**
 * Constancia de DT-e de SENASA (TPA-64, Res. 356/2008 y modificatorias; senasa-planillas.md §2). Dos esquemas:
 * - `dte.356_2008/1`: constancia/impresión del DT-e emitido en SIGSA (Mi SENASA, Clave Fiscal).
 * - `dte.dta_historico/1`: DTA en papel (antecedente): sin código de verificación ni estado electrónico.
 * TROPILLA no emite el DT-e ni lo valida contra SIGSA: la verificación oficial es la de SENASA (CUVE / consulta).
 */
final class Dte extends FamiliaMovimiento
{
    public const ESQUEMA = 'dte.356_2008/1';

    public const ESQUEMA_DTA = 'dte.dta_historico/1';

    public function codigo(): string
    {
        return 'dte';
    }

    public function tipoDocumento(): string
    {
        return 'dte_copia';
    }

    protected function variantes(): array
    {
        return ['dte_356_2008' => self::ESQUEMA, 'dta_historico' => self::ESQUEMA_DTA];
    }

    protected function escalares(string $variante): array
    {
        $electronico = $variante !== 'dta_historico';

        return array_filter([
            'numero' => ['documento', $electronico ? 'N.º de DT-e' : 'N.º de DTA', 'texto', null, true],
            'codigo_verificacion' => $electronico ? ['documento', 'Código de verificación (CUVE)', 'texto', null, false] : null,
            'fecha_emision' => ['documento', 'Fecha de emisión', 'fecha', null, true],
            'vigencia_hasta' => ['documento', 'Válido hasta', 'fecha', null, false],
            'estado_documento' => $electronico ? ['documento', 'Estado (emitido, cerrado, anulado)', 'texto', null, false] : null,
            'motivo' => ['documento', 'Motivo / destino', 'opcion', self::MOTIVOS, false],
            ...$this->comunes(),
            'precintos' => ['transporte', 'Precintos', 'texto', null, false],
            'requisitos_sanitarios' => ['sanitario', 'Requisitos sanitarios (vacunación, brucelosis…)', 'texto', null, false],
            'fidha_numero' => ['sanitario', 'N.º de FIDHA (garrapata)', 'texto', null, false],
            'observaciones' => ['sanitario', 'Observaciones', 'texto', null, false],
        ]);
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es una constancia de movimiento de hacienda de SENASA, Argentina. Puede ser:
        - "dte_356_2008": Documento de Tránsito Electrónico (DT-e) emitido en SIGSA, impreso o en PDF.
        - "dta_historico": DTA (Documento para el Tránsito de Animales) en papel, anterior al DT-e.
        - "desconocida": si no es un DT-e/DTA o no podés distinguirlo.
        Transcribí cada dato tal como figura (fechas y números como están, sin reformatear). Para cada campo: valor (null si
        está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6), pagina (1..n) y alternativas si un
        trazo admite otra lectura. RENSPA con el formato 00.000.0.00000/00 tal como aparece.
        Campos: numero del documento, codigo_verificacion (CUVE o código de validación si figura), fecha_emision,
        vigencia_hasta (válido hasta / vencimiento), estado_documento, motivo (faena, invernada, cría, remate feria,
        exposición, mismo titular, exportación, venta u otro, como figure), origen (renspa_origen, titular_origen, cuit_origen,
        establecimiento_origen, localidad_origen con departamento/provincia), destino (mismos datos con _destino), transporte
        (transportista, cuit_transportista, patentes de chasis y acoplado, precintos), requisitos_sanitarios, fidha_numero,
        total_cabezas (total de animales) y observaciones.
        detalle: una fila por especie y categoría (especie "bovino" o "bubalino", categoría como figura, cantidad y marca/señal).
        problemas: páginas cortadas, borrosas, con reflejo o sombra que impidan leer algo.
        TXT;
    }

    protected function nombreEsquema(string $esquema): string
    {
        return $esquema === self::ESQUEMA_DTA ? 'DTA en papel (histórico)' : 'DT-e (Res. SENASA 356/2008)';
    }

    protected function nombreCorto(): string
    {
        return 'el DT-e';
    }

    protected function titulosGrupos(): array
    {
        return ['documento' => 'Datos del DT-e', ...parent::titulosGrupos()];
    }

    protected function numeroDte(array $campos): ?string
    {
        return Campos::valor($campos, 'numero');
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $adv = parent::advertencias($est, $campos, $salida);
        $emision = Campos::valor($campos, 'fecha_emision');
        $hasta = Campos::valor($campos, 'vigencia_hasta');
        if ($emision !== null && $hasta !== null && $hasta < $emision) {
            $adv[] = ['codigo' => 'vigencia_invalida', 'mensaje' => __('La fecha «válido hasta» es anterior a la emisión.'), 'ruta' => 'vigencia_hasta'];
        }
        $estado = mb_strtolower((string) Campos::valor($campos, 'estado_documento'));
        if (str_contains($estado, 'anul')) {
            $adv[] = ['codigo' => 'documento_anulado', 'mensaje' => __('El DT-e figura como anulado.'), 'ruta' => 'estado_documento'];
        }

        return $adv;
    }

    public function metadatosDocumento(array $campos): array
    {
        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha_emision'),
            'emisor' => ['nombre' => 'SENASA (SIGSA)', ...array_filter(['identificacion' => Campos::valor($campos, 'numero')])],
        ]);
    }
}
