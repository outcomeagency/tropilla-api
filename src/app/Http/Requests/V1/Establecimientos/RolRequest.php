<?php

namespace App\Http\Requests\V1\Establecimientos;

use App\Domain\Establecimientos\Enums\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RolRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rol' => ['required', Rule::in(Rol::invitables())],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
