<?php

namespace App\Domain\Campos;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Data\Provincias;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mercado de campos y pastajes (TPA-74, investigacion/negocio-datos.md §2.4). Vocabulario, textos de límites y serialización.
 * Lo publicado es declaración del anunciante: TROPILLA no inspecciona ni garantiza título, agua, receptividad ni disponibilidad.
 */
final class Campos
{
    public const MODALIDADES = [
        'pastaje' => 'Pastaje',
        'arrendamiento' => 'Arrendamiento',
        'capitalizacion' => 'Capitalización',
    ];

    public const PRECIO_UNIDADES = [
        'por_cabeza_mes' => 'por cabeza por mes',
        'por_ha_anio' => 'por hectárea por año',
        'kg_carne_ha_anio' => 'kg de carne por hectárea por año',
        'porcentaje_ganancia' => '% de los kilos ganados',
        'a_convenir' => 'a convenir',
    ];

    public const AGUA = [
        'aguada_natural' => 'Aguada natural', 'tajamar' => 'Tajamar / represa', 'rio_arroyo' => 'Río o arroyo',
        'perforacion' => 'Perforación', 'molino' => 'Molino', 'bebederos' => 'Bebederos',
    ];

    public const INSTALACIONES = [
        'manga' => 'Manga', 'corrales' => 'Corrales', 'balanza' => 'Balanza', 'embarcadero' => 'Embarcadero',
        'casa_puesto' => 'Casa / puesto', 'galpon' => 'Galpón', 'potreros_divididos' => 'Potreros divididos',
    ];

    public const ALAMBRADO = ['bueno' => 'Bueno', 'regular' => 'Regular', 'a_reparar' => 'A reparar'];

    /** Nivel real de comprobación que hizo el equipo de TROPILLA (lo asigna solo staff). */
    public const VERIFICACION = [
        'declarado' => ['nombre' => 'Datos declarados', 'detalle' => 'Lo publicó el anunciante. TROPILLA no verificó el contacto ni la documentación.'],
        'contacto_verificado' => ['nombre' => 'Contacto verificado', 'detalle' => 'El equipo de TROPILLA habló con el anunciante y confirmó que el aviso existe. No se inspeccionó el campo.'],
        'documentacion_revisada' => ['nombre' => 'Documentación revisada', 'detalle' => 'El anunciante mostró documentación del campo al equipo de TROPILLA. No es estudio de título ni inspección.'],
    ];

    public const MOTIVOS_DISPUTA = [
        'aviso_inexistente' => 'El campo no existe o no está disponible',
        'datos_falsos' => 'Los datos del aviso no coinciden con la realidad',
        'incumplimiento' => 'Incumplimiento de lo acordado',
        'conducta' => 'Trato o conducta inadecuada',
        'otro' => 'Otro motivo',
    ];

    public const ESTADOS_CONSULTA = [
        'enviada' => 'Enviada', 'respondida' => 'Respondida', 'visita_coordinada' => 'Visita coordinada', 'acuerdo' => 'Acuerdo',
        'sin_acuerdo' => 'Sin acuerdo', 'descartada' => 'Descartada', 'retirada' => 'Retirada',
    ];

    public const CONSULTAS_ABIERTAS = ['enviada', 'respondida', 'visita_coordinada'];

    /** Antes de mandar hacienda a un campo ajeno: diligencias a cargo de las partes. */
    public const CHECKLIST = [
        'Visitar el campo antes de cerrar trato, con el asesor si es posible.',
        'Pedir título o contrato que acredite que el anunciante puede disponer del campo.',
        'Recorrer aguadas, alambrados, manga y accesos en la época de uso.',
        'Acordar por escrito carga máxima, plazo, precio, sanidad, mortandad y quién responde por faltantes.',
        'Revisar RENSPA, estado sanitario y zona (garrapata) para el movimiento de hacienda.',
        'Consultar a un abogado o corredor matriculado sobre el contrato rural aplicable.',
    ];

    public const AVISOS = [
        'Los avisos son declaraciones de quien publica. TROPILLA no inspecciona los campos ni garantiza título, agua, receptividad ni disponibilidad.',
        'La receptividad depende del manejo y de la época del año: confirmala en la visita.',
        'TROPILLA no es parte del contrato ni cobra comisión en este piloto. Si algo sale mal, reportalo y el equipo revisa el caso.',
    ];

    public const CONSENTIMIENTO_VERSION = '2026-10-17';

    /** @return list<string> */
    public static function provinciasPiloto(): array
    {
        return array_values(array_map('strval', (array) config('tropilla.campos.provincias_piloto')));
    }

    /** Ubicación publicable: provincia y departamento; la localidad solo si el anunciante lo eligió. @return array<string, mixed> */
    public static function zona(object $a, bool $completa): array
    {
        $depto = DB::table('geo_departamentos')->where('codigo', $a->departamento_codigo)->value('nombre');

        return [
            'provincia_codigo' => $a->provincia_codigo, 'provincia' => Provincias::TODAS[$a->provincia_codigo] ?? null,
            'departamento_codigo' => $a->departamento_codigo, 'departamento' => $depto,
            'localidad' => ($completa || $a->mostrar_localidad) ? $a->localidad : null,
        ];
    }

    /**
     * CampoAviso. `$propio` agrega lo que solo ve el anunciante (estado interno, notas, contadores de consultas/disputas).
     *
     * @return array<string, mixed>
     */
    public static function aviso(object $a, bool $propio = false): array
    {
        $est = DB::table('establecimientos as e')->leftJoin('perfiles_publicos as p', fn ($j) => $j->on('p.establecimiento_id', '=', 'e.id')->where('p.publicado', true))
            ->where('e.id', $a->establecimiento_id)->first(['e.nombre', 'p.slug']);
        $salida = [
            'id' => $a->id, 'titulo' => $a->titulo, 'descripcion' => $a->descripcion,
            'modalidad' => $a->modalidad, 'modalidad_nombre' => self::MODALIDADES[$a->modalidad] ?? $a->modalidad,
            'especies' => PgTextArray::parse($a->especies), 'categorias' => PgTextArray::parse($a->categorias),
            'capacidad_cabezas' => (int) $a->capacidad_cabezas, 'superficie_ha' => Formato::decimal($a->superficie_ha),
            'temporada_desde' => $a->temporada_desde, 'temporada_hasta' => $a->temporada_hasta, 'plazo_minimo_meses' => $a->plazo_minimo_meses === null ? null : (int) $a->plazo_minimo_meses,
            'precio' => ['unidad' => $a->precio_unidad, 'unidad_nombre' => self::PRECIO_UNIDADES[$a->precio_unidad] ?? $a->precio_unidad, 'valor' => Formato::decimal($a->precio_valor)],
            'condiciones' => $a->condiciones,
            'agua' => PgTextArray::parse($a->agua), 'alambrado' => $a->alambrado, 'instalaciones' => PgTextArray::parse($a->instalaciones), 'accesos' => $a->accesos,
            'zona' => self::zona($a, $propio), 'mostrar_localidad' => (bool) $a->mostrar_localidad,
            'estado' => $a->estado,
            'verificacion' => ['nivel' => $a->verificacion, ...self::VERIFICACION[$a->verificacion], 'verificado_en' => $a->verificado_en ? Formato::fechaHora(Carbon::parse($a->verificado_en)) : null],
            'publicado_por' => ['establecimiento' => $est?->nombre, 'perfil_slug' => $est?->slug],
            'publicado_en' => $a->publicado_en ? Formato::fechaHora(Carbon::parse($a->publicado_en)) : null,
            'actualizado_en' => Formato::fechaHora(Carbon::parse($a->updated_at)),
        ];
        if ($propio) {
            $salida += [
                'establecimiento_id' => $a->establecimiento_id, 'row_version' => (int) $a->row_version,
                'verificacion_nota' => $a->verificacion_nota, 'motivo_cierre' => $a->motivo_cierre,
                'cerrado_en' => $a->cerrado_en ? Formato::fechaHora(Carbon::parse($a->cerrado_en)) : null,
                'consultas_abiertas' => DB::table('campo_consultas')->where('aviso_id', $a->id)->whereIn('estado', self::CONSULTAS_ABIERTAS)->count(),
                'consultas_total' => DB::table('campo_consultas')->where('aviso_id', $a->id)->count(),
                'disputas_abiertas' => DB::table('campo_disputas')->where('aviso_id', $a->id)->whereIn('estado', ['abierta', 'en_revision'])->count(),
            ];
        }

        return $salida;
    }

    /**
     * CampoConsulta. El anunciante ve el contacto del interesado (lo consintió al enviar); el interesado ve el del anunciante
     * recién cuando este responde.
     *
     * @return array<string, mixed>
     */
    public static function consulta(object $c, string $lado): array
    {
        $aviso = DB::table('campo_avisos')->where('id', $c->aviso_id)->first();
        $interesado = DB::table('establecimientos')->where('id', $c->establecimiento_id)->first(['nombre', 'provincia_codigo', 'departamento_codigo']);
        $salida = [
            'id' => $c->id, 'lado' => $lado,
            'aviso' => ['id' => $aviso->id, 'titulo' => $aviso->titulo, 'modalidad' => $aviso->modalidad, 'estado' => $aviso->estado, 'zona' => self::zona($aviso, false)],
            'interesado' => ['establecimiento' => $interesado?->nombre, 'provincia' => Provincias::TODAS[$interesado?->provincia_codigo ?? ''] ?? null],
            'mensaje' => $c->mensaje, 'especie' => $c->especie_codigo, 'categorias' => PgTextArray::parse($c->categorias), 'cabezas' => (int) $c->cabezas,
            'desde' => $c->desde, 'hasta' => $c->hasta,
            'estado' => $c->estado, 'estado_nombre' => self::ESTADOS_CONSULTA[$c->estado] ?? $c->estado,
            'respuesta' => $c->respuesta, 'nota_cierre' => $c->nota_cierre,
            'contacto' => $lado === 'recibida' ? json_decode($c->contacto_interesado, true) : ($c->contacto_anunciante ? json_decode($c->contacto_anunciante, true) : null),
            'created_at' => Formato::fechaHora(Carbon::parse($c->created_at)),
            'respondida_en' => $c->respondida_en ? Formato::fechaHora(Carbon::parse($c->respondida_en)) : null,
            'cerrada_en' => $c->cerrada_en ? Formato::fechaHora(Carbon::parse($c->cerrada_en)) : null,
        ];
        if ($lado === 'enviada') {
            $salida['publicado_por'] = DB::table('establecimientos')->where('id', $aviso->establecimiento_id)->value('nombre');
        }

        return $salida;
    }

    /** CampoDisputa. La identidad de quien reporta no se muestra al anunciante. @return array<string, mixed> */
    public static function disputa(object $d, bool $staff = false): array
    {
        $salida = [
            'id' => $d->id, 'aviso_id' => $d->aviso_id, 'consulta_id' => $d->consulta_id,
            'motivo' => $d->motivo, 'motivo_nombre' => self::MOTIVOS_DISPUTA[$d->motivo] ?? $d->motivo, 'detalle' => $d->detalle,
            'estado' => $d->estado, 'descargo' => $d->descargo, 'descargo_en' => $d->descargo_en ? Formato::fechaHora(Carbon::parse($d->descargo_en)) : null,
            'resolucion' => $d->resolucion, 'accion' => $d->accion,
            'resuelta_en' => $d->resuelta_en ? Formato::fechaHora(Carbon::parse($d->resuelta_en)) : null,
            'created_at' => Formato::fechaHora(Carbon::parse($d->created_at)),
        ];
        if ($staff) {
            $u = DB::table('usuarios')->where('id', $d->denunciante_id)->first(['nombre', 'email', 'telefono']);
            $a = DB::table('campo_avisos')->where('id', $d->aviso_id)->first(['titulo', 'estado', 'verificacion', 'establecimiento_id']);
            $salida += [
                'denunciante' => ['nombre' => $u?->nombre, 'email' => $u?->email, 'telefono' => $u?->telefono],
                'aviso' => ['titulo' => $a?->titulo, 'estado' => $a?->estado, 'verificacion' => $a?->verificacion,
                    'establecimiento' => DB::table('establecimientos')->where('id', $a?->establecimiento_id)->value('nombre')],
            ];
        }

        return $salida;
    }

    /** @return array{nombre: string, email: ?string, telefono: ?string} */
    public static function contactoDe(string $usuarioId): array
    {
        $u = DB::table('usuarios')->where('id', $usuarioId)->first(['nombre', 'email', 'telefono']);

        return ['nombre' => (string) $u->nombre, 'email' => $u->email, 'telefono' => $u->telefono];
    }
}
