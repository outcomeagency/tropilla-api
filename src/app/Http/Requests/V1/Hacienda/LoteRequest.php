<?php

namespace App\Http\Requests\V1\Hacienda;

use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;

/** LoteInput (POST y PATCH). */
class LoteRequest extends FormRequest
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
            'especie' => [$req, 'string', 'exists:especies,codigo'],
            'proposito' => ['sometimes', 'nullable', 'in:cria,recria,engorde,reproductores,venta,descarte'],
            'anonimo' => ['sometimes', 'boolean'],
            'notas' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
