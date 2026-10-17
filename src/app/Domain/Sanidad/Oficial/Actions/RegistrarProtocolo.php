<?php

namespace App\Domain\Sanidad\Oficial\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Registra un protocolo de muestreo/prueba de brucelosis o TBC (TPA-66) con su resultado por animal, en una transacción:
 * - cabecera + filas `protocolo_resultados` (append-only), tal como figuran en el original;
 * - un `sanidad.prueba_diagnostica` por resultado asociado a un animal (positivo → reactor por el proyector de siempre);
 *   las filas sin lecturas se agrupan por resultado en tandas de 1000 animales, las de tuberculina con lecturas van de a una.
 * Idempotente por `id`. Advertencias (animales sin asociar, positivos, fecha futura, vencido) piden `confirmar_advertencias`.
 * Origen `transcripcion_revisada` exige el documento original del establecimiento; `declarado` es carga sin respaldo.
 */
final class RegistrarProtocolo
{
    public function __construct(
        private readonly RegistrarEvento $registrarEvento,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $d  datos validados (ver ProtocoloRequest)
     * @return array{0: object, 1: bool} [protocolo, creado]
     */
    public function handle(Establecimiento $est, User $u, array $d, bool $confirmarAdvertencias, ?string $borradorId = null): array
    {
        $id = strtolower($d['id'] ?? (string) Str::uuid7());
        if ($existente = DB::table('protocolos_sanitarios')->where('id', $id)->first()) {
            if ($existente->establecimiento_id !== $est->id) {
                throw new ApiException(409, 'id_reutilizado', __('Ese id ya se usó.'));
            }

            return [$existente, false];
        }

        $prueba = DB::table('tipos_prueba')->where('codigo', $d['prueba_codigo'])->first();
        if ($prueba === null || ($prueba->enfermedad !== null && $prueba->enfermedad !== $d['enfermedad'])) {
            throw ApiException::validacion(__('La prueba no corresponde a la enfermedad.'), ['prueba_codigo' => [__('Elegí una prueba de :e.', ['e' => $d['enfermedad']])]]);
        }
        $this->documento($est, $d['origen'], $d['documento_id'] ?? null);

        $hoy = Carbon::now($est->zona_horaria)->toDateString();
        $filas = $this->filas($est, $d['resultados']);
        $venceEl = $d['vence_el'] ?? SanidadOficial::venceMovimiento($est->id, $d['enfermedad'], $d['motivo'], $d['fecha_muestreo']);

        $adv = $this->advertencias($d, $filas, $hoy, $venceEl);
        if ($adv !== [] && ! $confirmarAdvertencias) {
            throw new ApiException(422, 'requiere_confirmacion', __('Revisá las advertencias y confirmá para registrar igual.'), [], $adv);
        }
        if (array_filter($filas, fn ($f) => $f['animal_id'] !== null) !== []) {
            Gate::forUser($u)->authorize('sanidad.crear', $est);
        }

        $p = DB::transaction(function () use ($est, $u, $d, $id, $filas, $venceEl, $borradorId, $prueba) {
            DB::table('protocolos_sanitarios')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'enfermedad' => $d['enfermedad'], 'prueba_codigo' => $d['prueba_codigo'], 'motivo' => $d['motivo'],
                'modalidad' => $d['modalidad'] ?? null, 'stock_base' => $d['stock_base'] ?? null, 'fecha_muestreo' => $d['fecha_muestreo'],
                'fecha_resultado' => $d['fecha_resultado'] ?? null, 'laboratorio' => $d['laboratorio'] ?? null, 'protocolo_numero' => $d['protocolo_numero'] ?? null,
                'veterinario_nombre' => $d['veterinario_nombre'] ?? null, 'veterinario_matricula' => $d['veterinario_matricula'] ?? null, 'vence_el' => $venceEl,
                'origen' => $d['origen'], 'documento_id' => $d['documento_id'] ?? null, 'borrador_id' => $borradorId, 'observaciones' => $d['observaciones'] ?? null,
                'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $eventoPorFila = $this->eventos($est, $u, $d, $filas, $prueba);
            foreach ($filas as $i => $f) {
                DB::table('protocolo_resultados')->insert([
                    'protocolo_id' => $id, 'orden' => $i + 1, 'animal_id' => $f['animal_id'], 'identificacion' => $f['identificacion'],
                    'categoria_codigo' => $f['categoria_codigo'], 'muestra' => $f['muestra'], 'resultado' => $f['resultado'],
                    'lectura_0h_mm' => $f['lectura_0h_mm'], 'lectura_72h_mm' => $f['lectura_72h_mm'], 'observaciones' => $f['observaciones'],
                    'evento_id' => $eventoPorFila[$i] ?? null,
                ]);
            }
            $eventoIds = array_values(array_unique(array_filter($eventoPorFila)));
            DB::table('protocolos_sanitarios')->where('id', $id)->update(['evento_ids' => json_encode($eventoIds)]);
            $this->auditoria->handle($est->id, $u->id, 'protocolo_sanitario', $id, 'alta', null, [
                'enfermedad' => $d['enfermedad'], 'prueba' => $d['prueba_codigo'], 'origen' => $d['origen'], 'resultados' => count($filas), 'eventos' => count($eventoIds),
            ]);

            return DB::table('protocolos_sanitarios')->where('id', $id)->first();
        });

        return [$p, true];
    }

    private function documento(Establecimiento $est, string $origen, ?string $documentoId): void
    {
        if ($documentoId === null) {
            if ($origen === 'transcripcion_revisada') {
                throw ApiException::validacion(__('Una transcripción revisada lleva el documento original.'), ['documento_id' => [__('Adjuntá el original.')]]);
            }

            return;
        }
        $doc = DB::table('documentos')->where('id', strtolower($documentoId))->where('establecimiento_id', $est->id)->first();
        if ($doc === null) {
            throw ApiException::validacion(__('El documento no existe en este establecimiento.'), ['documento_id' => [__('No encontrado.')]]);
        }
        if ($doc->privacidad === 'fiscal') {
            throw ApiException::validacion(__('No se puede usar un documento fiscal como respaldo sanitario.'), ['documento_id' => [__('Documento fiscal.')]]);
        }
    }

    /**
     * Normaliza filas: asocia la identificación transcripta a un animal cuando no viene `animal_id`.
     *
     * @param  list<array<string, mixed>>  $resultados
     * @return list<array<string, mixed>>
     */
    private function filas(Establecimiento $est, array $resultados): array
    {
        $out = [];
        $vistos = [];
        foreach ($resultados as $i => $r) {
            $animalId = isset($r['animal_id']) ? strtolower($r['animal_id']) : null;
            $ident = isset($r['identificacion']) && trim((string) $r['identificacion']) !== '' ? mb_substr(trim($r['identificacion']), 0, 100) : null;
            if ($animalId !== null) {
                $a = DB::table('animales_estado')->where('animal_id', $animalId)->where('establecimiento_id', $est->id)->first(['animal_id', 'identificacion_principal']);
                if ($a === null) {
                    throw ApiException::validacion(__('Fila :n: el animal no es de este establecimiento.', ['n' => $i + 1]), ["resultados.{$i}.animal_id" => [__('No encontrado.')]]);
                }
                $ident ??= $a->identificacion_principal;
            } elseif ($ident !== null) {
                $animalId = SanidadOficial::animalPorIdentificacion($est->id, $ident);
            } else {
                throw ApiException::validacion(__('Fila :n: falta el animal o su identificación.', ['n' => $i + 1]), ["resultados.{$i}.identificacion" => [__('Requerido.')]]);
            }
            if ($animalId !== null && isset($vistos[$animalId])) {
                throw ApiException::validacion(__('Fila :n: el animal ya figura en la fila :m.', ['n' => $i + 1, 'm' => $vistos[$animalId] + 1]), ["resultados.{$i}" => [__('Duplicado.')]]);
            }
            if ($animalId !== null) {
                $vistos[$animalId] = $i;
            }
            $out[] = [
                'animal_id' => $animalId, 'identificacion' => $ident, 'categoria_codigo' => $r['categoria_codigo'] ?? null, 'muestra' => $r['muestra'] ?? null,
                'resultado' => $r['resultado'], 'lectura_0h_mm' => $r['lectura_0h_mm'] ?? null, 'lectura_72h_mm' => $r['lectura_72h_mm'] ?? null,
                'observaciones' => $r['observaciones'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return list<array{codigo: string, mensaje: string, detalle: object}>
     */
    private function advertencias(array $d, array $filas, string $hoy, ?string $venceEl): array
    {
        $adv = [];
        $sinAnimal = array_values(array_filter($filas, fn ($f) => $f['animal_id'] === null));
        if ($sinAnimal !== []) {
            $adv[] = ['codigo' => 'animal_no_asociado', 'mensaje' => __(':n identificaciones no coinciden con animales de TROPILLA: quedan en el protocolo sin pasar a las fichas.', ['n' => count($sinAnimal)]),
                'detalle' => (object) ['identificaciones' => array_slice(array_column($sinAnimal, 'identificacion'), 0, 50)]];
        }
        $positivos = array_filter($filas, fn ($f) => $f['resultado'] === 'positivo' && $f['animal_id'] !== null);
        if ($positivos !== []) {
            $adv[] = ['codigo' => 'marca_reactor', 'mensaje' => __(':n animales con resultado positivo quedan marcados como reactores (dato privado).', ['n' => count($positivos)]),
                'detalle' => (object) ['animal_ids' => array_values(array_column($positivos, 'animal_id'))]];
        }
        if ($d['fecha_muestreo'] > $hoy || (($d['fecha_resultado'] ?? null) !== null && $d['fecha_resultado'] > $hoy)) {
            $adv[] = ['codigo' => 'fecha_futura', 'mensaje' => __('La fecha del muestreo o del resultado es posterior a hoy.'), 'detalle' => (object) []];
        }
        if (($d['fecha_resultado'] ?? null) !== null && $d['fecha_resultado'] < $d['fecha_muestreo']) {
            $adv[] = ['codigo' => 'resultado_antes_del_muestreo', 'mensaje' => __('La fecha del resultado es anterior a la del muestreo.'), 'detalle' => (object) []];
        }
        if ($venceEl !== null && $venceEl < $hoy) {
            $adv[] = ['codigo' => 'protocolo_vencido', 'mensaje' => __('El resultado ya venció el :f para movimientos.', ['f' => $venceEl]), 'detalle' => (object) ['vence_el' => $venceEl]];
        }
        if (($d['stock_base'] ?? null) !== null && ($d['modalidad'] ?? null) === 'total' && count($filas) < (int) $d['stock_base']) {
            $adv[] = ['codigo' => 'muestreo_incompleto', 'mensaje' => __('Modalidad total: hay :n resultados para un stock de :s.', ['n' => count($filas), 's' => $d['stock_base']]),
                'detalle' => (object) ['resultados' => count($filas), 'stock_base' => (int) $d['stock_base']]];
        }

        return $adv;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<int, string> índice de fila => id del evento que la registra
     */
    private function eventos(Establecimiento $est, User $u, array $d, array $filas, object $prueba): array
    {
        $obs = mb_substr($d['origen'] === 'transcripcion_revisada'
            ? __('Protocolo :p: transcripción revisada del original. No reemplaza el registro oficial (SIGSA).', ['p' => $d['protocolo_numero'] ?? 's/n'])
            : __('Protocolo :p cargado por el productor. No reemplaza el registro oficial (SIGSA).', ['p' => $d['protocolo_numero'] ?? 's/n']), 0, 2000);
        $base = array_filter([
            'prueba' => $prueba->codigo, 'resultado_origen' => 'declarado',
            'laboratorio' => isset($d['laboratorio']) ? mb_substr($d['laboratorio'], 0, 200) : null,
            'protocolo_numero' => isset($d['protocolo_numero']) ? mb_substr($d['protocolo_numero'], 0, 200) : null,
        ], fn ($v) => $v !== null);

        // Grupos: [payload, índices]; las lecturas en mm son de un solo animal.
        $grupos = [];
        foreach ($filas as $i => $f) {
            if ($f['animal_id'] === null) {
                continue;
            }
            $resultado = $f['resultado'] === 'no_apto' ? 'no_concluyente' : $f['resultado'];
            if ($f['lectura_0h_mm'] !== null || $f['lectura_72h_mm'] !== null) {
                $grupos[] = [array_filter([...$base, 'resultado' => $resultado, 'lectura_0h_mm' => $f['lectura_0h_mm'], 'lectura_72h_mm' => $f['lectura_72h_mm']], fn ($v) => $v !== null), [$i]];

                continue;
            }
            $grupos['r:'.$resultado] ??= [[...$base, 'resultado' => $resultado], []];
            $grupos['r:'.$resultado][1][] = $i;
        }

        $out = [];
        foreach ($grupos as [$payload, $indices]) {
            foreach (array_chunk($indices, 1000) as $tanda) {
                $ids = array_map(fn ($i) => $filas[$i]['animal_id'], $tanda);
                sort($ids);
                $in = json_decode(json_encode(['id' => (string) Str::uuid7(), 'tipo' => 'sanidad.prueba_diagnostica',
                    'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids], 'payload' => $payload, 'ocurrido_en' => $d['fecha_resultado'] ?? $d['fecha_muestreo'],
                    'observaciones' => $obs]), false);
                $r = $this->registrarEvento->handle($est, $u, ArmarSobreWeb::alta($est, $u, $in), ['canal' => 'web', 'confirmar_advertencias' => true]);
                foreach ($tanda as $i) {
                    $out[$i] = $r->evento->id;
                }
            }
        }

        return $out;
    }
}
