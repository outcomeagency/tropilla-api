<?php

namespace App\Domain\Campos\Actions;

use App\Casts\PgTextArray;
use App\Domain\Campos\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consultas por un campo (TPA-74). El interesado envía su rodeo y consiente compartir su contacto con el anunciante; el anunciante
 * comparte el suyo al responder. Ambos registran el resultado (visita, acuerdo, sin acuerdo): es la métrica del piloto.
 */
final class GestionarConsulta
{
    /** Transiciones por lado: estado actual => estados posibles. */
    private const ANUNCIANTE = [
        'enviada' => ['respondida', 'descartada'],
        'respondida' => ['visita_coordinada', 'acuerdo', 'sin_acuerdo'],
        'visita_coordinada' => ['acuerdo', 'sin_acuerdo'],
    ];

    private const INTERESADO = [
        'enviada' => ['retirada'],
        'respondida' => ['visita_coordinada', 'acuerdo', 'sin_acuerdo', 'retirada'],
        'visita_coordinada' => ['acuerdo', 'sin_acuerdo', 'retirada'],
    ];

    public function __construct(private readonly Notificador $notificador) {}

    /** @param array<string, mixed> $d */
    public function enviar(object $aviso, Establecimiento $interesado, User $u, array $d): object
    {
        if ($aviso->estado !== 'publicado') {
            throw ApiException::reglaBloqueante('El aviso no está publicado.');
        }
        $esPropio = $aviso->establecimiento_id === $interesado->id || DB::table('membresias')->where('establecimiento_id', $aviso->establecimiento_id)
            ->where('usuario_id', $u->id)->where('estado', 'activa')->exists();
        if ($esPropio) {
            throw ApiException::reglaBloqueante('No podés consultar un aviso de tu propio establecimiento.');
        }
        if (! in_array($d['especie'], PgTextArray::parse($aviso->especies), true)) {
            throw ApiException::validacion('El campo no recibe esa especie.', ['especie' => ['El campo no recibe esa especie.']]);
        }
        $hoy = Carbon::now($interesado->zona_horaria)->startOfDay();
        if (DB::table('campo_consultas')->where('establecimiento_id', $interesado->id)->where('created_at', '>=', $hoy)->count() >= (int) config('tropilla.campos.max_consultas_dia')) {
            throw new ApiException(429, 'demasiados_intentos', 'Llegaste al tope de consultas de hoy. Probá mañana o esperá respuestas.');
        }
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($aviso, $interesado, $u, $d, $id) {
            $abierta = DB::table('campo_consultas')->where('aviso_id', $aviso->id)->where('establecimiento_id', $interesado->id)
                ->whereIn('estado', Campos::CONSULTAS_ABIERTAS)->lockForUpdate()->exists();
            if ($abierta) {
                throw ApiException::reglaBloqueante('Ya tenés una consulta abierta por este campo.');
            }
            DB::table('campo_consultas')->insert([
                'id' => $id, 'aviso_id' => $aviso->id, 'establecimiento_id' => $interesado->id, 'usuario_id' => $u->id,
                'mensaje' => $d['mensaje'], 'especie_codigo' => $d['especie'], 'cabezas' => $d['cabezas'],
                'categorias' => PgTextArray::literal(array_values(array_unique((array) ($d['categorias'] ?? [])))),
                'desde' => $d['desde'] ?? null, 'hasta' => $d['hasta'] ?? null,
                'contacto_interesado' => json_encode(Campos::contactoDe($u->id), JSON_UNESCAPED_UNICODE),
                'consentimiento' => json_encode(['compartir_contacto' => true, 'version' => Campos::CONSENTIMIENTO_VERSION, 'aceptado_en' => now()->toIso8601String()]),
                'estado' => 'enviada', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->notificador->aRoles($aviso->establecimiento_id, Notificador::rolesCon('campos.gestionar'), 'campo_consulta', 'Nueva consulta por tu campo',
            "{$interesado->nombre} pregunta por «{$aviso->titulo}» para {$d['cabezas']} cabezas.", ['consulta_id' => $id, 'aviso_id' => $aviso->id, 'ruta' => '/campos/consultas'], "campo_consulta:{$id}");

        return DB::table('campo_consultas')->where('id', $id)->first();
    }

    /** @param array{estado: string, respuesta?: ?string, nota?: ?string} $d */
    public function avanzar(object $consulta, string $lado, User $u, array $d): object
    {
        return DB::transaction(function () use ($consulta, $lado, $u, $d) {
            $c = DB::table('campo_consultas')->where('id', $consulta->id)->lockForUpdate()->first();
            $permitidos = ($lado === 'recibida' ? self::ANUNCIANTE : self::INTERESADO)[$c->estado] ?? [];
            if (! in_array($d['estado'], $permitidos, true)) {
                throw ApiException::reglaBloqueante('La consulta está «'.(Campos::ESTADOS_CONSULTA[$c->estado] ?? $c->estado).'»: no se puede pasar a «'.(Campos::ESTADOS_CONSULTA[$d['estado']] ?? $d['estado']).'».');
            }
            $fila = ['estado' => $d['estado'], 'updated_at' => now()];
            if ($d['estado'] === 'respondida') {
                if (blank($d['respuesta'] ?? null)) {
                    throw ApiException::validacion('Escribí la respuesta.', ['respuesta' => ['Escribí la respuesta.']]);
                }
                $fila += ['respuesta' => $d['respuesta'], 'respondida_en' => now(), 'contacto_anunciante' => json_encode(Campos::contactoDe($u->id), JSON_UNESCAPED_UNICODE)];
            }
            if (in_array($d['estado'], ['acuerdo', 'sin_acuerdo', 'descartada', 'retirada'], true)) {
                $fila += ['cerrada_en' => now(), 'nota_cierre' => $d['nota'] ?? null];
            }
            DB::table('campo_consultas')->where('id', $c->id)->update($fila);
            $aviso = DB::table('campo_avisos')->where('id', $c->aviso_id)->first();
            if ($lado === 'recibida') {
                $titulo = $d['estado'] === 'respondida' ? 'Te respondieron por un campo' : 'Novedades de tu consulta por un campo';
                $this->notificador->aUsuario($c->usuario_id, $c->establecimiento_id, 'campo_respuesta', $titulo,
                    "«{$aviso->titulo}»: ".(Campos::ESTADOS_CONSULTA[$d['estado']] ?? $d['estado']).'.', ['consulta_id' => $c->id, 'ruta' => '/campos/consultas'], "campo_respuesta:{$c->id}:{$d['estado']}");
            } else {
                $this->notificador->aRoles($aviso->establecimiento_id, Notificador::rolesCon('campos.gestionar'), 'campo_consulta', 'Novedades de una consulta por tu campo',
                    "«{$aviso->titulo}»: ".(Campos::ESTADOS_CONSULTA[$d['estado']] ?? $d['estado']).'.', ['consulta_id' => $c->id, 'ruta' => '/campos/consultas'], "campo_consulta:{$c->id}:{$d['estado']}");
            }

            return DB::table('campo_consultas')->where('id', $c->id)->first();
        });
    }
}
