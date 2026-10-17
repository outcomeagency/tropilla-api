<?php

namespace App\Http\Requests\V1\Forraje;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** RevisionBriefInput: revisión humana documentada del brief (piloto con asesor). */
class RevisionBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('forraje.revisar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resultado' => ['required', 'in:revisado,con_observaciones'],
            'nota' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
