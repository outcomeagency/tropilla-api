<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Schema `MotivoInput` (revocar, archivar, anular...). */
class MotivoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:500']];
    }
}
