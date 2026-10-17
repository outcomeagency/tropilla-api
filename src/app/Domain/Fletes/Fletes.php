<?php

namespace App\Domain\Fletes;

use App\Domain\Establecimientos\Data\Provincias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogos y reglas del piloto concierge de camiones jaula (TPA-73, investigacion/negocio-datos.md §2.3).
 * TROPILLA acerca opciones y deja constancia; no es transportista, no fija precios y no emite el DT-e.
 */
final class Fletes
{
    /** Versión del texto de consentimiento del productor (se guarda con cada pedido). */
    public const CONSENTIMIENTO_VERSION = '2026-10-17';

    public const CONSENTIMIENTO = 'Acepto que el equipo de TROPILLA comparta mi nombre, teléfono, zona de carga (departamento), ventana de fechas, '
        .'destino (provincia y localidad) y cantidad de animales con los transportistas que evalúe para este pedido, y mis datos de contacto con el '
        .'transportista cuya propuesta yo acepte. No se publican trayectos ni fechas.';

    public const JAULAS = [
        'chasis' => 'Chasis jaula',
        'acoplado' => 'Chasis con acoplado',
        'semi' => 'Semirremolque jaula',
        'doble_piso' => 'Jaula de doble piso (terneros)',
    ];

    public const MOTIVOS = [
        'venta_frigorifico' => 'Venta a frigorífico',
        'remate_feria' => 'Remate o feria',
        'venta_campo' => 'Venta directa en campo',
        'traslado_propio' => 'Traslado entre campos propios',
        'pastaje' => 'Pastaje o capitalización',
        'otro' => 'Otro',
    ];

    /** Checklist documental del transportista: lo verifica el equipo a mano con el documento a la vista o ante la autoridad. */
    public const DOCUMENTOS_TRANSPORTISTA = [
        'identidad_cuit' => 'Identidad y CUIT del transportista confirmados (contra suplantación)',
        'senasa_vehiculo' => 'Vehículo inscripto y habilitado por SENASA para transporte de animales vivos',
        'ruta' => 'Inscripción vigente en el RUTA (Registro Único del Transporte Automotor)',
        'licencia_chofer' => 'Licencia Nacional Habilitante del chofer para cargas',
        'rto' => 'Revisión técnica obligatoria (RTO/VTV) vigente',
        'seguro' => 'Seguro de responsabilidad civil vigente (y de carga, si lo ofrece)',
    ];

    public const METODOS_VERIFICACION = [
        'documento_a_la_vista' => 'Documento original a la vista',
        'consulta_autoridad' => 'Consulta ante el organismo',
        'copia_digital' => 'Copia digital legible',
    ];

    /** Diligencias del productor para el viaje. El DT-e lo emite quien corresponde ante SENASA, nunca TROPILLA. */
    public const CHECKLIST_PEDIDO = [
        'dte' => 'DT-e emitido ante SENASA por quien corresponde (TROPILLA no lo emite)',
        'documentos_transportista' => 'Viste la documentación del camión y del chofer el día de la carga',
        'lavado_desinfeccion' => 'El camión llega lavado y desinfectado, con constancia',
        'condiciones_escritas' => 'Precio, fecha, horario de carga y forma de pago acordados por escrito',
        'bienestar' => 'Carga sin sobrecarga, piso antideslizante y horario adecuado para los animales',
        'responsabilidad' => 'Quedó claro quién responde por accidente, mortandad o demora en el viaje',
    ];

    public const AVISOS = [
        'TROPILLA no emite ni gestiona el DT-e: lo hace quien corresponde ante SENASA.',
        'TROPILLA acerca opciones y deja constancia de lo verificado. No es el transportista ni fija el precio: el acuerdo es entre vos y el transportista.',
        'La documentación se verifica a mano y con fecha; puede vencer o cambiar. Revisala el día de la carga.',
        'Piloto concierge: cada pedido lo gestiona una persona del equipo. No hay respuesta automática ni garantía de conseguir camión.',
    ];

    public const ESTADOS_ABIERTOS = ['pendiente', 'en_gestion', 'propuesto', 'contactado'];

    /** @return list<string> códigos de provincia del corredor piloto */
    public static function corredor(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('tropilla.fletes.corredor')))));
    }

    /** @return array<string, mixed> */
    public static function corredorInfo(): array
    {
        $cod = self::corredor();

        return ['provincias' => array_map(fn ($c) => ['codigo' => $c, 'nombre' => Provincias::TODAS[$c] ?? $c], $cod),
            'radio_km' => (int) config('tropilla.fletes.radio_km')];
    }

    public static function departamentoNombre(?string $codigo): ?string
    {
        if ($codigo === null) {
            return null;
        }

        return DB::table('geo_departamentos')->where('codigo', $codigo)->value('nombre') ?? $codigo;
    }

    /**
     * Estado documental de un transportista: completo si cada ítem está verificado (o no aplica) y no vencido.
     *
     * @return array{completa: bool, faltantes: list<string>, items: list<array<string, mixed>>}
     */
    public static function documentacion(string $transportistaId, ?Carbon $al = null): array
    {
        $al ??= Carbon::today();
        $marcas = DB::table('fletes_transportista_documentos as d')->leftJoin('usuarios as u', 'u.id', '=', 'd.verificado_por')
            ->where('d.transportista_id', $transportistaId)->get(['d.*', 'u.nombre as verificado_por_nombre'])->keyBy('item');
        $items = [];
        $faltantes = [];
        foreach (self::DOCUMENTOS_TRANSPORTISTA as $item => $texto) {
            $m = $marcas[$item] ?? null;
            $vencido = $m && $m->vence_el && Carbon::parse($m->vence_el)->lt($al);
            $estado = $m->estado ?? 'pendiente';
            $ok = in_array($estado, ['verificado', 'no_aplica'], true) && ! $vencido;
            if (! $ok) {
                $faltantes[] = $item;
            }
            $items[] = [
                'item' => $item, 'texto' => $texto, 'estado' => $vencido ? 'vencido' : $estado, 'metodo' => $m->metodo ?? null,
                'vence_el' => $m->vence_el ?? null, 'nota' => $m->nota ?? null, 'verificado_por' => $m->verificado_por_nombre ?? null,
                'actualizado_en' => $m ? Carbon::parse($m->updated_at)->utc()->format('Y-m-d\TH:i:s.v\Z') : null,
            ];
        }

        return ['completa' => $faltantes === [], 'faltantes' => $faltantes, 'items' => $items];
    }

    /** @param array<string, mixed> $detalle */
    public static function bitacora(string $usuarioId, string $accion, ?string $pedidoId = null, ?string $transportistaId = null, array $detalle = []): void
    {
        DB::table('fletes_bitacora')->insert([
            'id' => (string) Str::uuid7(), 'pedido_id' => $pedidoId, 'transportista_id' => $transportistaId, 'usuario_id' => $usuarioId,
            'accion' => $accion, 'detalle' => json_encode((object) $detalle, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
