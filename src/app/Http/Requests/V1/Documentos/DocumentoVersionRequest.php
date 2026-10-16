<?php

namespace App\Http\Requests\V1\Documentos;

use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** DocumentoVersionInput: nueva versión (archivos nuevos), corrección de metadatos o revocación, siempre con motivo. */
class DocumentoVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('documentos.versionar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'nullable', Reglas::UUID],
            'accion' => ['required', 'in:nueva_version,correccion_metadatos,revocacion'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'tipo' => ['sometimes', Rule::exists('tipos_documento', 'codigo')],
            'privacidad' => ['sometimes', Rule::in(RegistrarDocumentoVersion::PRIVACIDADES)],
            'titulo' => ['sometimes', 'string', 'max:200'],
            'emisor' => ['sometimes', 'nullable', 'array'],
            'emisor.nombre' => ['sometimes', 'nullable', 'string', 'max:200'],
            'emisor.identificacion' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emitido_en' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vence_en' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vinculos' => ['sometimes', 'array', 'max:200'],
            'vinculos.*.tipo' => ['required', Rule::in(array_keys(RegistrarDocumentoVersion::VINCULOS))],
            'vinculos.*.id' => ['required', Reglas::UUID],
            'archivos' => ['required_if:accion,nueva_version', 'array', 'max:50'],
            'archivos.*.sha256' => ['required', Reglas::HASH],
            'archivos.*.bytes' => ['required', 'integer', 'min:1', 'max:104857600'],
            'archivos.*.mime' => ['required', 'string', 'max:100'],
        ];
    }
}
