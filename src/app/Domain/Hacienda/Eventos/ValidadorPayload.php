<?php

namespace App\Domain\Hacienda\Eventos;

use Illuminate\Support\Facades\DB;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valida el `payload` contra el JSON Schema de `tipos_evento_versiones` (tipo + tipo_version):
 * la misma fuente que baja la app por /sync/catalogos. Los eventos viejos se siguen validando con su versión.
 */
final class ValidadorPayload
{
    /** @var array<string, object> */
    private static array $schemas = [];

    /**
     * @return array<string, list<string>> errores por ruta (`payload.animales.0.sexo`), vacío si valida
     */
    public static function validar(string $tipo, int $version, mixed $payload): array
    {
        $clave = "{$tipo}@{$version}";
        if (! isset(self::$schemas[$clave])) {
            $json = DB::table('tipos_evento_versiones')->where('tipo_codigo', $tipo)->where('version', $version)->selectRaw('json_schema::text as s')->value('s');
            if ($json === null) {
                throw new EventoRechazado('tipo_desconocido', "El tipo {$tipo} v{$version} no existe en el servidor.");
            }
            self::$schemas[$clave] = json_decode($json, false);
        }

        $validator = new Validator;
        $validator->setMaxErrors(10);
        $resultado = $validator->validate($payload, self::$schemas[$clave]);
        if ($resultado->isValid()) {
            return [];
        }

        $errores = [];
        foreach ((new ErrorFormatter)->format($resultado->error(), true) as $ruta => $mensajes) {
            $clave = 'payload'.str_replace('/', '.', rtrim((string) $ruta, '/'));
            $errores[$clave === 'payload.' ? 'payload' : $clave] = array_values((array) $mensajes);
        }

        return $errores;
    }
}
