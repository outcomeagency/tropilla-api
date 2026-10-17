<?php

namespace App\Http\Requests\V1\Contrapartes;

use App\Domain\Contrapartes\ChequeoContraparte;
use App\Domain\Contrapartes\Cuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * ConsultaContraparteInput. La consulta exige finalidad y declaración de consentimiento; si el CUIT es de una persona humana,
 * además que el titular autorizó la consulta (Ley 25.326; criterio conservador hasta el dictamen legal).
 */
class ConsultaContraparteRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('contrapartes.consultar', $this->route('establecimiento'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cuit'))) {
            $this->merge(['cuit' => Cuit::normalizar($this->input('cuit'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cuit' => ['required', 'string', 'digits:11'],
            'finalidad' => ['required', 'in:'.implode(',', array_keys(ChequeoContraparte::FINALIDADES))],
            'finalidad_detalle' => ['nullable', 'string', 'max:300', 'required_if:finalidad,otra'],
            'consentimiento' => ['required', 'accepted'],
            'titular_autorizo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $cuit = (string) $this->input('cuit');
            if (! Cuit::valido($cuit)) {
                $v->errors()->add('cuit', 'El CUIT no es válido (revisá el dígito verificador).');

                return;
            }
            if (Cuit::tipoPersona($cuit) === 'humana' && ! $this->boolean('titular_autorizo')) {
                $v->errors()->add('titular_autorizo', 'Es el CUIT de una persona: confirmá que el titular te autorizó a consultar sus datos.');
            }
        }];
    }
}
