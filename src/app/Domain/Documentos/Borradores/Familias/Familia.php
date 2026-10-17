<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Establecimientos\Models\Establecimiento;

/**
 * Familia documental extraíble (investigacion/senasa-planillas.md § Esquemas de extracción por familia).
 * Define qué se le pide al modelo, cómo se normaliza su salida a campos revisables (versionados por `esquema`), qué
 * advertencias se calculan (sin autocorregir) y qué eventos propone al confirmar.
 *
 * Un campo revisable es: {ruta, grupo, etiqueta, tipo, opciones?, critico, valor_extraido, valor, confianza, pagina,
 * alternativas, estado} con `estado` ∈ pendiente | correcto | editado | no_legible | no_aplica.
 */
interface Familia
{
    public function codigo(): string;

    /** Tipo de documento del catálogo con el que se captura. */
    public function tipoDocumento(): string;

    public function instrucciones(): string;

    /** @return array<string, mixed> JSON Schema de la salida del modelo */
    public function esquemaSalida(): array;

    /**
     * @param  array<string, mixed>  $salida
     * @return array{esquema: string, campos: list<array<string, mixed>>}
     */
    public function normalizar(array $salida): array;

    /**
     * Filas vacías para agregar a mano lo que el modelo no vio.
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<array<string, mixed>>
     */
    public function filaNueva(string $grupo, array $campos): array;

    /**
     * @param  list<array<string, mixed>>  $campos
     * @param  array<string, mixed>  $salida  salida cruda (problemas de imagen, variante)
     * @return list<array{codigo: string, mensaje: string, ruta?: string, detalle?: array<string, mixed>}>
     */
    public function advertencias(Establecimiento $est, array $campos, array $salida): array;

    /**
     * Valida un valor editado por la persona para el campo. Devuelve el valor normalizado o lanza ApiException.
     *
     * @param  array<string, mixed>  $campo
     */
    public function validarValor(array $campo, mixed $valor): mixed;

    /**
     * Qué hace la confirmación: eventos nuevos (con sujetos ya resueltos; uno por sujeto) y eventos existentes a los
     * que se vincula el documento (p. ej. el movimiento ya cargado de un DT-e). Lanza `regla_bloqueante` si no hay nada
     * que registrar o falta resolver a quién se aplica.
     *
     * @param  list<array<string, mixed>>  $campos
     * @return array{eventos: list<array{tipo: string, ocurrido_en: string, payload: array<string, mixed>, sujetos: list<array<string, mixed>>, observaciones: string}>, vincular: list<string>}
     */
    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array;

    /**
     * Completa los campos propios de TROPILLA después de leer (sugerencias que la persona confirma).
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<array<string, mixed>>
     */
    public function completar(Establecimiento $est, array $campos): array;

    /**
     * Opciones que dependen del establecimiento (lotes, movimientos) al momento de mostrar.
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<array<string, mixed>>
     */
    public function refrescarOpciones(string $establecimientoId, array $campos): array;

    /** @return list<string> códigos de advertencia que impiden confirmar (se corrigen, no se aceptan). */
    public function bloqueantes(): array;

    /** @return array{esquemas: array<string, string>, grupos: array<string, string>, agregables: list<array{grupo: string, etiqueta: string}>} */
    public function presentacion(): array;

    /**
     * Metadatos con los que se versiona el documento al confirmar (emisor, fecha de emisión).
     *
     * @param  list<array<string, mixed>>  $campos
     * @return array{emitido_en?: string, emisor?: array{nombre: string, identificacion?: string}}
     */
    public function metadatosDocumento(array $campos): array;
}
