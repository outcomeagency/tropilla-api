<?php

namespace App\Http\Resources\V1\Comercial;

use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schema `Consulta` con links de respuesta prearmados (WhatsApp / mailto) en el idioma del comprador. */
class ConsultaResource extends JsonResource
{
    private const SALUDO = ['es' => 'Hola :n, gracias por tu consulta sobre :t.', 'en' => 'Hi :n, thanks for your inquiry about :t.', 'pt' => 'Olá :n, obrigado pela sua consulta sobre :t.'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $oferta = $c->oferta_id ? DB::table('ofertas')->where('id', $c->oferta_id)->first(['id', 'textos', 'codigo_publico']) : null;
        $tituloOferta = $oferta ? ((json_decode($oferta->textos, true)[$c->idioma]['titulo'] ?? json_decode($oferta->textos, true)['es']['titulo'] ?? $oferta->codigo_publico)) : null;
        $est = DB::table('establecimientos')->where('id', $c->establecimiento_id)->value('nombre');
        $texto = strtr(self::SALUDO[$c->idioma] ?? self::SALUDO['es'], [':n' => $c->nombre, ':t' => $tituloOferta ?? $est]);
        $tel = $c->telefono ? preg_replace('/\D/', '', $c->telefono) : null;
        $fecha = fn ($v) => $v ? Formato::fechaHora(Carbon::parse($v)) : null;

        return [
            'id' => $c->id, 'origen' => $c->origen, 'estado' => $c->estado, 'idioma' => $c->idioma,
            'nombre' => $c->nombre, 'empresa' => $c->empresa, 'pais' => $c->pais, 'email' => $c->email, 'telefono' => $c->telefono,
            'tipo_comprador' => $c->tipo_comprador, 'volumen' => $c->volumen, 'mensaje' => $c->mensaje,
            // Las consultas públicas no están vinculadas a una cuenta compradora ni aportan CUIT; nunca inferimos identidad.
            'validacion_cuit_estado' => 'pendiente',
            'oferta' => $oferta ? ['id' => $oferta->id, 'titulo' => $tituloOferta] : null,
            'contrato' => $c->contrato_id ? ['id' => $c->contrato_id] : null,
            'whatsapp_url' => $tel ? 'https://wa.me/'.$tel.'?text='.rawurlencode($texto) : null,
            'mailto_url' => $c->email ? 'mailto:'.$c->email.'?subject='.rawurlencode('TROPILLA — '.($tituloOferta ?? $est)).'&body='.rawurlencode($texto) : null,
            'canal_respuesta' => $c->canal_respuesta,
            'respondida_en' => $fecha($c->respondida_en),
            'created_at' => $fecha($c->created_at),
        ];
    }
}
