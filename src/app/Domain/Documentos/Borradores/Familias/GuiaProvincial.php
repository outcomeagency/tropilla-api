<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;

/**
 * Guía provincial de traslado (TPA-64, senasa-planillas.md §2 y §9). Arranca con el **DUT de Chaco** (Documento Único
 * de Transporte: guía policial + DT-e + guía tributaria, dos hojas con detalle de animales y marcas, intervenciones de
 * despacho/destino y talones). Esquemas:
 * - `guia_provincial.dut_chaco/1`: verificado contra la descripción oficial del DUT.
 * - `guia_provincial.generica/1`: otras provincias (Corrientes, Formosa, Misiones): **modelo no verificado**, se lee con
 *   los mismos campos y se advierte. La guía de una provincia no sirve para otra; TROPILLA no la emite.
 */
final class GuiaProvincial extends FamiliaMovimiento
{
    public const ESQUEMA_DUT_CHACO = 'guia_provincial.dut_chaco/1';

    public const ESQUEMA_GENERICA = 'guia_provincial.generica/1';

    public function codigo(): string
    {
        return 'guia_provincial';
    }

    public function tipoDocumento(): string
    {
        return 'guia_provincial';
    }

    protected function variantes(): array
    {
        return ['dut_chaco' => self::ESQUEMA_DUT_CHACO, 'otra_provincia' => self::ESQUEMA_GENERICA];
    }

    protected function escalares(string $variante): array
    {
        return [
            'numero' => ['documento', $variante === 'dut_chaco' ? 'N.º de DUT' : 'N.º de guía', 'texto', null, true],
            'provincia' => ['documento', 'Provincia', 'texto', null, false],
            'fecha_emision' => ['documento', 'Fecha de emisión', 'fecha', null, true],
            'vigencia_hasta' => ['documento', 'Válida hasta', 'fecha', null, false],
            'dte_numero' => ['documento', 'N.º de DT-e asociado', 'texto', null, $variante === 'dut_chaco'],
            'motivo' => ['documento', 'Motivo / destino', 'opcion', self::MOTIVOS, false],
            'boleto_marca' => ['documento', 'Boleto de marca / señal', 'texto', null, false],
            ...$this->comunes(),
            'intervencion_despacho' => ['intervenciones', 'Intervención policial de despacho', 'texto', null, false],
            'intervencion_destino' => ['intervenciones', 'Intervención en destino', 'texto', null, false],
            'guia_tributaria' => ['intervenciones', 'Guía tributaria / talón', 'texto', null, false],
            'observaciones' => ['intervenciones', 'Observaciones', 'texto', null, false],
        ];
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es una guía provincial de traslado de hacienda de Argentina. Puede ser:
        - "dut_chaco": Documento Único de Transporte (DUT) de la provincia del Chaco, que reúne guía policial, DT-e y guía
          tributaria; tiene datos de la guía y del DT-e, detalle de animales y marcas, intervenciones policiales de
          despacho/tránsito/destino y talones tributarios.
        - "otra_provincia": guía de otra provincia (Corrientes, Formosa, Misiones u otra).
        - "desconocida": si no es una guía de traslado o no podés distinguirla.
        Transcribí cada dato tal como figura (fechas y números como están, sin reformatear). Para cada campo: valor (null si
        está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6), pagina (1..n) y alternativas si un
        trazo admite otra lectura. RENSPA con el formato 00.000.0.00000/00 tal como aparece.
        Campos: numero de guía/DUT, provincia, fecha_emision, vigencia_hasta, dte_numero (número del DT-e asociado),
        motivo, boleto_marca (número de boleto de marca o señal), origen (renspa_origen, titular_origen, cuit_origen,
        establecimiento_origen, localidad_origen con departamento), destino (mismos datos con _destino), transporte
        (transportista, cuit_transportista, patentes), intervencion_despacho y intervencion_destino (sello, fecha y
        dependencia policial), guia_tributaria (número de talón o comprobante), total_cabezas y observaciones.
        detalle: una fila por especie y categoría (especie "bovino" o "bubalino", categoría como figura, cantidad y marca/señal).
        problemas: páginas cortadas, borrosas, con reflejo o sombra que impidan leer algo.
        TXT;
    }

    protected function nombreEsquema(string $esquema): string
    {
        return $esquema === self::ESQUEMA_DUT_CHACO ? 'DUT Chaco' : 'Guía provincial (modelo no verificado)';
    }

    protected function nombreCorto(): string
    {
        return 'la guía';
    }

    protected function titulosGrupos(): array
    {
        return ['documento' => 'Datos de la guía', ...parent::titulosGrupos()];
    }

    protected function numeroDte(array $campos): ?string
    {
        return Campos::valor($campos, 'dte_numero');
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $adv = parent::advertencias($est, $campos, $salida);
        if (($salida['variante'] ?? null) === 'otra_provincia') {
            $adv[] = ['codigo' => 'variante_no_verificada', 'mensaje' => __('Por ahora solo el DUT de Chaco está verificado. Revisá cada dato contra la guía de tu provincia.')];
        }
        if (Campos::valor($campos, 'dte_numero') === null) {
            $adv[] = ['codigo' => 'dte_faltante', 'mensaje' => __('La guía no tiene el N.º de DT-e asociado: sin DT-e la hacienda no puede moverse.'), 'ruta' => 'dte_numero'];
        }

        return $adv;
    }

    public function metadatosDocumento(array $campos): array
    {
        $provincia = Campos::valor($campos, 'provincia');

        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha_emision'),
            'emisor' => ['nombre' => mb_substr($provincia ? 'Guía provincial — '.$provincia : 'Guía provincial', 0, 200), ...array_filter(['identificacion' => Campos::valor($campos, 'numero')])],
        ]);
    }
}
