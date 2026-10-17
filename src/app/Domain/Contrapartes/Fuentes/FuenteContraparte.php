<?php

namespace App\Domain\Contrapartes\Fuentes;

/**
 * Fuente pública consultable por CUIT (TPA-72). Cada adaptador devuelve un resultado con estado explícito para que la pantalla
 * nunca confunda "no hay datos" con "no se pudo consultar" o "fuente no configurada".
 *
 * Resultado: ['codigo', 'nombre', 'url', 'estado' => ok|sin_datos|no_configurada|error, 'consultado_en' => ISO|null,
 * 'mensaje' => string, 'datos' => array|null].
 */
interface FuenteContraparte
{
    public function codigo(): string;

    /** @return array{codigo: string, nombre: string, url: string, descripcion: string, cobertura: string, configurada: bool, como_corregir: string} */
    public function descripcion(): array;

    /** @return array{codigo: string, nombre: string, url: string, estado: string, consultado_en: ?string, mensaje: string, datos: ?array<string, mixed>} */
    public function consultar(string $cuit): array;
}
