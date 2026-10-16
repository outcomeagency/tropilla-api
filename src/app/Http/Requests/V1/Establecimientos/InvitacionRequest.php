<?php

namespace App\Http\Requests\V1\Establecimientos;

use App\Domain\Establecimientos\Enums\Rol;
use App\Http\Requests\V1\Auth\ReglasAuth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitacionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rol' => ['required', Rule::in(Rol::invitables())],
            'email' => ['nullable', 'required_without:telefono', 'email:rfc', 'max:254'],
            'telefono' => ['nullable', 'required_without:email', ReglasAuth::TELEFONO],
        ];
    }
}
