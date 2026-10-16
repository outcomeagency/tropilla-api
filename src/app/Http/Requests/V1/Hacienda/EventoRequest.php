<?php

namespace App\Http\Requests\V1\Hacienda;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EventoInput (web). Solo la forma de primer nivel: el payload lo valida el JSON Schema del tipo
 * dentro de RegistrarEvento (misma validación que el push).
 */
class EventoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'tipo' => ['required', 'string', 'max:64'],
            'tipo_version' => ['nullable', 'integer', 'min:1'],
            'sujetos' => ['required', 'array'],
            'sujetos.tipo' => ['required', 'in:animales,lote,potrero,establecimiento'],
            'payload' => ['present', 'array'],
            'ocurrido_en' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}\.\d{3}Z)?$/'],
            'responsable' => ['nullable', 'string', 'max:200'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'adjuntos' => ['nullable', 'array'],
            'adjuntos.*' => ['regex:/^[0-9a-f]{64}$/'],
            'confirmar_advertencias' => ['nullable', 'boolean'],
        ];
    }
}
