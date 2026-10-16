<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Comercial\Actions\RecibirConsultaPublica;
use App\Http\Controllers\Controller;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /public/consultas — contacto sin cuenta (TPA-55). */
class ConsultaPublicaController extends Controller
{
    private const RESPUESTA = [
        'es' => 'Tu consulta fue enviada al productor. Te va a responder por el medio que indicaste.',
        'en' => 'Your inquiry was sent to the producer. They will reply through the contact you provided.',
        'pt' => 'Sua consulta foi enviada ao produtor. Ele responderá pelo contato que você informou.',
    ];

    public function __invoke(Request $request, RecibirConsultaPublica $recibir): JsonResponse
    {
        $request->merge(['idioma' => $request->input('idioma') ?: Leyenda::locale()]);
        $d = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
            'empresa' => ['nullable', 'string', 'max:120'],
            'pais' => ['required', 'string', 'size:2', 'alpha'],
            'email' => ['nullable', 'required_without:telefono', 'email:rfc', 'max:254'],
            'telefono' => ['nullable', 'required_without:email', 'string', 'max:40'],
            'tipo_comprador' => ['required', 'in:frigorifico,exportador,gastronomico,institucion,productor,otro'],
            'volumen' => ['nullable', 'string', 'max:200'],
            'mensaje' => ['required', 'string', 'min:5', 'max:3000'],
            'idioma' => ['required', 'in:es,en,pt'],
            'establecimiento_slug' => ['nullable', 'string', 'max:60'],
            'oferta_codigo' => ['nullable', 'string', 'max:20'],
            'contrato_codigo' => ['nullable', 'string', 'max:20'],
            'captcha_token' => ['required', 'string', 'max:4096'],
            'website' => ['nullable', 'string', 'max:500'],
        ]);
        $recibir->handle($d, $request->ip(), $request->userAgent(), auth('sanctum')->user());

        return response()->json(['data' => ['recibida' => true, 'mensaje' => self::RESPUESTA[$d['idioma']]]], 201);
    }
}
