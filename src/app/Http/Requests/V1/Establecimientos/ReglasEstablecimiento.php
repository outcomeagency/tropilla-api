<?php

namespace App\Http\Requests\V1\Establecimientos;

use App\Domain\Catalogos\Models\GeoDepartamento;
use App\Domain\Establecimientos\Data\Provincias;
use App\Rules\Reglas;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validación de EstablecimientoInput (alta y PATCH). Las listas (actividades, instalaciones)
 * salen de `catalogo_items` cuando existe el catálogo; mientras tanto de los valores del modelo de datos.
 */
final class ReglasEstablecimiento
{
    public const ACTIVIDADES = ['cria', 'recria', 'invernada', 'feedlot', 'cabana', 'tambo'];

    public const INSTALACIONES = ['manga', 'balanza', 'cepo', 'embarcadero', 'aguadas', 'revolcaderos'];

    /** @return array<string, mixed> */
    public static function reglas(string $prefijo = '', bool $parcial = false, ?string $provincia = null): array
    {
        $req = $parcial ? 'sometimes' : 'required';

        return [
            $prefijo.'nombre' => [$req, 'string', 'max:120'],
            $prefijo.'provincia_codigo' => [$req, Rule::in(array_keys(Provincias::TODAS))],
            $prefijo.'departamento_codigo' => [$req, 'string', self::departamento($prefijo, $provincia)],
            $prefijo.'localidad' => ['sometimes', 'nullable', 'string', 'max:120'],
            $prefijo.'ubicacion' => ['sometimes', 'nullable', 'array:lat,lon'],
            $prefijo.'ubicacion.lat' => ['required_with:'.$prefijo.'ubicacion', 'string', Reglas::DECIMAL, 'numeric', 'between:-90,90'],
            $prefijo.'ubicacion.lon' => ['required_with:'.$prefijo.'ubicacion', 'string', Reglas::DECIMAL, 'numeric', 'between:-180,180'],
            $prefijo.'renspa' => ['sometimes', 'nullable', 'string', 'max:40'],
            $prefijo.'superficie_ha' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS, 'numeric', 'max:9999999999'],
            $prefijo.'actividades' => [$req, 'array', 'min:1'],
            $prefijo.'actividades.*' => ['distinct', Rule::in(self::ACTIVIDADES)],
            $prefijo.'especies' => [$req, 'array', 'min:1'],
            $prefijo.'especies.*' => ['distinct', Rule::in(['BOV', 'BUB'])],
            $prefijo.'instalaciones' => ['sometimes', 'array'],
            $prefijo.'instalaciones.*' => ['distinct', Rule::in(self::INSTALACIONES)],
            $prefijo.'zonas_sanitarias' => ['sometimes', 'array:aftosa_zona,garrapata,rabia_endemica,fasciola_endemica'],
            $prefijo.'zonas_sanitarias.aftosa_zona' => ['sometimes', 'nullable', 'string', 'max:80'],
            $prefijo.'zonas_sanitarias.garrapata' => ['sometimes', 'nullable', Rule::in(['erradicacion', 'control', 'libre'])],
            $prefijo.'zonas_sanitarias.rabia_endemica' => ['sometimes', 'boolean'],
            $prefijo.'zonas_sanitarias.fasciola_endemica' => ['sometimes', 'boolean'],
            $prefijo.'historia' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Departamento INDEC de la provincia elegida. Si la provincia tiene departamentos cargados en
     * `geo_departamentos`, tiene que existir; si no, basta el formato `<provincia><3 dígitos>`.
     */
    private static function departamento(string $prefijo, ?string $provinciaActual): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($prefijo, $provinciaActual) {
            $provincia = (string) (request()->input($prefijo.'provincia_codigo') ?? $provinciaActual);
            if (! is_string($value) || ! preg_match('/^'.preg_quote($provincia, '/').'\d{3}$/', $value)) {
                $fail(__('El departamento no corresponde a la provincia.'));

                return;
            }
            $cargados = GeoDepartamento::query()->where('provincia_codigo', $provincia)->exists();
            if ($cargados && ! GeoDepartamento::query()->whereKey($value)->exists()) {
                $fail(__('El departamento no existe.'));
            }
        };
    }
}
