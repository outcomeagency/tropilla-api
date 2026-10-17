<?php

namespace App\Domain\Contrapartes\Fuentes;

/**
 * ARCA — constancia de inscripción (padrón). El canal automatizable es el Web Service `ws_sr_constancia_inscripcion`, que exige
 * certificado digital, autorización WSAA y alta del servicio por un representado: no hay una API abierta sin credenciales.
 * La consulta web pública tiene CAPTCHA y **no** se automatiza ni se evade.
 *
 * Mientras `tropilla.contrapartes.arca_driver` sea `deshabilitado`, este adaptador devuelve `no_configurada` y la pantalla indica
 * cómo hacer la consulta manual (y tildarla en el checklist). Para habilitarlo: implementar el cliente WSAA + WS con el certificado
 * de TROPILLA (variables a definir) detrás de esta misma interfaz, previa revisión legal del uso.
 */
final class ArcaConstanciaInscripcion implements FuenteContraparte
{
    public const URL_CONSULTA_MANUAL = 'https://seti.arca.gob.ar/padron-puc-constancia-internet/jsp/Constancia.jsp';

    public function codigo(): string
    {
        return 'arca_constancia';
    }

    public function descripcion(): array
    {
        return [
            'codigo' => $this->codigo(),
            'nombre' => 'ARCA — Constancia de inscripción',
            'url' => self::URL_CONSULTA_MANUAL,
            'descripcion' => 'Identidad fiscal: denominación, domicilio fiscal, impuestos y actividades inscriptas.',
            'cobertura' => 'Requiere certificado y autorización de ARCA para consultarse automáticamente. Por ahora se consulta a mano en el sitio de ARCA.',
            'configurada' => false,
            'como_corregir' => 'El titular actualiza sus datos fiscales en ARCA con su clave fiscal.',
        ];
    }

    public function consultar(string $cuit): array
    {
        return ['codigo' => $this->codigo(), 'nombre' => 'ARCA — Constancia de inscripción', 'url' => self::URL_CONSULTA_MANUAL, 'estado' => 'no_configurada', 'consultado_en' => null,
            'mensaje' => 'TROPILLA todavía no consulta ARCA en forma automática (requiere certificado y revisión legal). Podés ver la constancia en el sitio de ARCA y marcarlo en el checklist.',
            'datos' => null];
    }
}
