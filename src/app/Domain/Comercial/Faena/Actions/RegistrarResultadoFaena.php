<?php

namespace App\Domain\Comercial\Faena\Actions;

use App\Domain\Comercial\Faena\Faena;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Decimales as D;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra el resultado de faena de una tropa (TPA-67): cabecera + una fila por res (append-only) tal como la informa el
 * frigorífico. Asocia cada res a un animal por su RFID/caravana cuando coincide (también vendidos o dados de baja) y la
 * vincula a la venta. No crea eventos ni toca pesos vivos: el peso de la res nunca se usa como peso del animal.
 * Idempotente por `id`. Advertencias que piden confirmación: reses sin asociar, animal que no estaba en la venta, cabezas
 * declaradas distintas, medias que no suman, pesos atípicos, fecha futura.
 */
final class RegistrarResultadoFaena
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  array<string, mixed>  $d
     * @return array{0: object, 1: bool}
     */
    public function handle(Establecimiento $est, User $u, array $d, bool $confirmarAdvertencias, ?string $borradorId = null): array
    {
        $id = strtolower($d['id'] ?? (string) Str::uuid7());
        if ($existente = DB::table('resultados_faena')->where('id', $id)->first()) {
            if ($existente->establecimiento_id !== $est->id) {
                throw new ApiException(409, 'id_reutilizado', __('Ese id ya se usó.'));
            }

            return [$existente, false];
        }
        if ($d['origen'] !== 'declarado' && empty($d['documento_id'])) {
            throw ApiException::validacion(__('Adjuntá el romaneo o la planilla del frigorífico.'), ['documento_id' => [__('Requerido para :o.', ['o' => $d['origen']])]]);
        }
        Referencias::validar($est, $d);
        $operacion = Referencias::operacion($est, $d['operacion_id'] ?? null);
        $enviados = $operacion ? DB::table('operacion_animales')->where('operacion_id', $operacion->id)->pluck('animal_id')->all()
            : Referencias::animalesEgreso($est, $d['egreso_evento_id'] ?? null);

        $reses = $this->reses($est, $d['reses']);
        $adv = $this->advertencias($est, $d, $reses, $enviados, $operacion);
        if ($adv !== [] && ! $confirmarAdvertencias) {
            throw new ApiException(422, 'requiere_confirmacion', __('Revisá las advertencias y confirmá para registrar igual.'), [], $adv);
        }

        $r = DB::transaction(function () use ($est, $u, $d, $id, $reses, $borradorId) {
            DB::table('resultados_faena')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'operacion_id' => isset($d['operacion_id']) ? strtolower($d['operacion_id']) : null,
                'egreso_evento_id' => isset($d['egreso_evento_id']) ? strtolower($d['egreso_evento_id']) : null,
                'frigorifico' => $d['frigorifico'], 'frigorifico_cuit' => $d['frigorifico_cuit'] ?? null, 'faenador_numero' => $d['faenador_numero'] ?? null,
                'fecha_faena' => $d['fecha_faena'], 'tropa_numero' => $d['tropa_numero'] ?? null, 'romaneo_numero' => $d['romaneo_numero'] ?? null,
                'dte_numero' => $d['dte_numero'] ?? null, 'cabezas_declaradas' => $d['cabezas_declaradas'] ?? null, 'tipificador' => $d['tipificador'] ?? null,
                'origen' => $d['origen'], 'documento_id' => isset($d['documento_id']) ? strtolower($d['documento_id']) : null, 'borrador_id' => $borradorId,
                'liquidacion' => isset($d['liquidacion']) ? json_encode(Referencias::liquidacion($d['liquidacion']), JSON_UNESCAPED_UNICODE) : null,
                'liquidacion_documento_id' => isset($d['liquidacion_documento_id']) ? strtolower($d['liquidacion_documento_id']) : null,
                'factura_documento_id' => isset($d['factura_documento_id']) ? strtolower($d['factura_documento_id']) : null,
                'observaciones' => $d['observaciones'] ?? null, 'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($reses as $i => $x) {
                DB::table('faena_reses')->insert(['resultado_id' => $id, 'orden' => $i + 1, ...$x]);
            }
            $this->auditoria->handle($est->id, $u->id, 'resultado_faena', $id, 'alta', null, [
                'origen' => $d['origen'], 'reses' => count($reses), 'operacion_id' => $d['operacion_id'] ?? null, 'tropa' => $d['tropa_numero'] ?? null,
            ]);

            return DB::table('resultados_faena')->where('id', $id)->first();
        });

        return [$r, true];
    }

    /**
     * @param  list<array<string, mixed>>  $entrada
     * @return list<array<string, mixed>>
     */
    private function reses(Establecimiento $est, array $entrada): array
    {
        $out = [];
        $garrones = [];
        $animales = [];
        foreach ($entrada as $i => $r) {
            $garron = trim((string) $r['garron']);
            if (isset($garrones[$garron])) {
                throw ApiException::validacion(__('Fila :n: el garrón :g ya figura en la fila :m.', ['n' => $i + 1, 'g' => $garron, 'm' => $garrones[$garron] + 1]), ["reses.{$i}.garron" => [__('Repetido.')]]);
            }
            $garrones[$garron] = $i;
            $ident = isset($r['identificacion']) && trim((string) $r['identificacion']) !== '' ? mb_substr(trim($r['identificacion']), 0, 100) : null;
            $animal = isset($r['animal_id']) ? strtolower($r['animal_id']) : ($ident ? SanidadOficial::animalPorIdentificacion($est->id, $ident) : null);
            if ($animal !== null && isset($r['animal_id']) && ! DB::table('animales')->where('id', $animal)->where('establecimiento_id', $est->id)->exists()) {
                throw ApiException::validacion(__('Fila :n: el animal no es de este establecimiento.', ['n' => $i + 1]), ["reses.{$i}.animal_id" => [__('No encontrado.')]]);
            }
            if ($animal !== null && isset($animales[$animal])) {
                throw ApiException::validacion(__('Fila :n: el animal ya figura en el garrón :g.', ['n' => $i + 1, 'g' => $animales[$animal]]), ["reses.{$i}.identificacion" => [__('Repetido.')]]);
            }
            if ($animal !== null) {
                $animales[$animal] = $garron;
            }
            $a = isset($r['media_a_kg']) ? Faena::kg($r['media_a_kg']) : null;
            $b = isset($r['media_b_kg']) ? Faena::kg($r['media_b_kg']) : null;
            $peso = isset($r['peso_kg']) ? Faena::kg($r['peso_kg']) : ($a !== null && $b !== null ? Faena::sumar($a, $b) : null);
            if ($peso === null) {
                throw ApiException::validacion(__('Fila :n: falta el peso de la res o de las dos medias.', ['n' => $i + 1]), ["reses.{$i}.peso_kg" => [__('Requerido.')]]);
            }
            $out[] = [
                'garron' => mb_substr($garron, 0, 40), 'identificacion' => $ident, 'animal_id' => $animal, 'media_a_kg' => $a, 'media_b_kg' => $b, 'peso_kg' => $peso,
                'categoria' => $r['categoria'] ?? null, 'tipificacion' => $r['tipificacion'] ?? null, 'decomiso' => $r['decomiso'] ?? null,
                'observaciones' => $r['observaciones'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $reses
     * @param  list<string>  $enviados
     * @return list<array{codigo: string, mensaje: string, detalle: object}>
     */
    private function advertencias(Establecimiento $est, array $d, array $reses, array $enviados, ?object $operacion): array
    {
        $adv = [];
        $sin = array_values(array_filter($reses, fn ($r) => $r['identificacion'] !== null && $r['animal_id'] === null));
        if ($sin !== []) {
            $adv[] = ['codigo' => 'res_sin_asociar', 'mensaje' => __(':n reses tienen una identificación que no coincide con animales de TROPILLA.', ['n' => count($sin)]),
                'detalle' => (object) ['garrones' => array_slice(array_column($sin, 'garron'), 0, 50)]];
        }
        if ($enviados !== []) {
            $fuera = array_values(array_filter($reses, fn ($r) => $r['animal_id'] !== null && ! in_array($r['animal_id'], $enviados, true)));
            if ($fuera !== []) {
                $adv[] = ['codigo' => 'animal_fuera_de_la_venta', 'mensaje' => __(':n reses son de animales que no estaban en la venta vinculada.', ['n' => count($fuera)]),
                    'detalle' => (object) ['garrones' => array_column($fuera, 'garron')]];
            }
        }
        $cabezas = $d['cabezas_declaradas'] ?? ($operacion ? (int) $operacion->cantidad : null);
        if ($cabezas !== null && (int) $cabezas !== count($reses)) {
            $adv[] = ['codigo' => 'cabezas_distintas', 'mensaje' => __('Se cargaron :r reses y la tropa declara :c cabezas.', ['r' => count($reses), 'c' => $cabezas]),
                'detalle' => (object) ['reses' => count($reses), 'cabezas' => (int) $cabezas]];
        }
        foreach ($reses as $r) {
            if ($r['media_a_kg'] !== null && $r['media_b_kg'] !== null && D::cmp(D::sub(Faena::sumar($r['media_a_kg'], $r['media_b_kg']), $r['peso_kg']), '0') !== 0) {
                $adv[] = ['codigo' => 'medias_no_suman', 'mensaje' => __('Garrón :g: las medias suman :s kg y el peso dice :p kg.', ['g' => $r['garron'], 's' => Faena::sumar($r['media_a_kg'], $r['media_b_kg']), 'p' => $r['peso_kg']]),
                    'detalle' => (object) ['garron' => $r['garron']]];
            }
            if (D::cmp($r['peso_kg'], Faena::RES_MIN_KG) < 0 || D::cmp($r['peso_kg'], Faena::RES_MAX_KG) > 0) {
                $adv[] = ['codigo' => 'peso_atipico', 'mensaje' => __('Garrón :g: :p kg es un peso de res poco habitual.', ['g' => $r['garron'], 'p' => $r['peso_kg']]),
                    'detalle' => (object) ['garron' => $r['garron'], 'peso_kg' => $r['peso_kg']]];
            }
        }
        if ($d['fecha_faena'] > Carbon::now($est->zona_horaria)->toDateString()) {
            $adv[] = ['codigo' => 'fecha_futura', 'mensaje' => __('La fecha de faena es posterior a hoy.'), 'detalle' => (object) []];
        }
        if ($operacion !== null && $d['fecha_faena'] < Carbon::parse($operacion->fecha)->toDateString()) {
            $adv[] = ['codigo' => 'faena_antes_de_la_venta', 'mensaje' => __('La faena es anterior a la fecha de la venta vinculada.'), 'detalle' => (object) []];
        }

        return $adv;
    }
}
