<?php

namespace App\Http\Requests\V1\Hacienda;

use App\Rules\Catalogo;
use App\Rules\Reglas;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** PotreroInput (POST y PATCH). Ambiente y recurso forrajero = catálogos `ambientes` / `recursos_forrajeros`. */
class PotreroRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patch = $this->isMethod('patch');
        $req = $patch ? 'sometimes' : 'required';

        return [
            'id' => $patch ? ['prohibited'] : ['sometimes', 'nullable', Reglas::UUID],
            'row_version' => $patch ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'nombre' => [$req, 'string', 'max:80'],
            'superficie_ha' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS, 'numeric', 'max:99999999'],
            'ambiente' => ['sometimes', 'nullable', Catalogo::existe('ambientes')],
            'recurso_forrajero' => ['sometimes', 'nullable', Catalogo::existe('recursos_forrajeros')],
            'agua' => ['sometimes', 'boolean'],
            'revolcadero' => ['sometimes', 'boolean'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'poligono' => ['sometimes', 'nullable', 'array', $this->poligonoValido()],
        ];
    }

    /** GeoJSON Polygon: anillo exterior cerrado, ≥ 4 posiciones [lon, lat] dentro de rango. */
    private function poligonoValido(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null) {
                return;
            }
            $anillos = $value['coordinates'] ?? null;
            if (($value['type'] ?? null) !== 'Polygon' || ! is_array($anillos) || $anillos === []) {
                $fail(__('El polígono debe ser un GeoJSON Polygon.'));

                return;
            }
            foreach ($anillos as $anillo) {
                if (! is_array($anillo) || count($anillo) < 4 || $anillo[0] !== end($anillo)) {
                    $fail(__('Cada anillo del polígono debe estar cerrado y tener al menos 4 puntos.'));

                    return;
                }
                foreach ($anillo as $p) {
                    if (! is_array($p) || count($p) < 2 || ! is_numeric($p[0]) || ! is_numeric($p[1]) || abs($p[0]) > 180 || abs($p[1]) > 90) {
                        $fail(__('Coordenadas inválidas en el polígono.'));

                        return;
                    }
                }
            }
        };
    }
}
