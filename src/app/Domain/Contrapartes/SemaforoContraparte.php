<?php

namespace App\Domain\Contrapartes;

use App\Domain\Documentos\Borradores\Extraccion\Extractor;
use Illuminate\Support\Facades\DB;

/** Reglas guardadas en contraparte_reglas; la síntesis solo recibe señales que ya existen. */
final class SemaforoContraparte
{
    public function calcularCheque(array $resultado): array
    {
        $denunciado = (bool) ($resultado['datos']['denunciado'] ?? false);
        $rules = DB::table('contraparte_reglas')->where('activa', true)->where('senal', 'cheque_denunciado')->orderBy('codigo')->get();
        $regla = $denunciado ? $rules->first(fn ($r) => $this->cumple(1, $r->operador, (float) $r->umbral)) : null;
        $senales = $regla ? [['id' => $regla->codigo, 'codigo' => 'cheque_denunciado', 'severidad' => $regla->severidad,
            'descripcion' => 'El BCRA informa una denuncia coincidente para este cheque.', 'valor' => 1, 'umbral' => (float) $regla->umbral,
            'version_regla' => $regla->version, 'fuente' => 'BCRA — Cheques denunciados', 'fecha' => $resultado['consultado_en'] ?? now()->toIso8601String()]] : [];

        return ['nivel' => $regla?->severidad ?? 'verde', 'reglas_version' => (int) ($rules->max('version') ?? 1), 'senales' => $senales];
    }

    public function calcular(string $cuit, array $informe, string $establecimientoId): array
    {
        $fecha = now()->toIso8601String();
        $bcra = collect($informe['fuentes'] ?? [])->firstWhere('codigo', 'bcra_central_deudores');
        $datos = ($bcra['estado'] ?? null) === 'ok' ? ($informe['bcra'] ?? null) : null;
        $actual = $datos['actual'] ?? null;
        $comprador = DB::table('compradores')->where('cuit_hash', hash('sha256', $cuit))->first();
        $tomas = $comprador ? DB::table('tomas as t')->join('contratos_futuro as c', 'c.id', '=', 't.contrato_id')
            ->where('c.establecimiento_id', $establecimientoId)->where('t.comprador_id', $comprador->usuario_id)
            ->whereIn('t.estado', ['reservada', 'pago_informado', 'confirmada'])->orderByDesc('t.created_at')->limit(20)->pluck('t.kg')->map(fn ($n) => (float) $n)->all() : [];
        $facts = [];
        if ($actual && $actual['peor_situacion'] !== null) {
            $facts['situacion_bcra'] = ['valor' => (int) $actual['peor_situacion'], 'descripcion' => 'Situación más alta informada por entidades al período '.$actual['periodo'], 'fuente' => 'BCRA — Central de Deudores', 'fecha' => $bcra['consultado_en'] ?? $fecha];
        }
        if (isset($datos['cheques']['total'])) {
            $facts['cheques_rechazados'] = ['valor' => (int) $datos['cheques']['total'], 'descripcion' => 'Cheques rechazados informados al BCRA: '.(int) $datos['cheques']['total'], 'fuente' => 'BCRA — Central de Deudores', 'fecha' => $bcra['consultado_en'] ?? $fecha];
        }
        if ($comprador && $comprador->validacion_cuit_estado === 'inconsistente') {
            $facts['razon_social_inconsistente'] = ['valor' => 1, 'descripcion' => 'La razón social declarada no coincide con la informada por BCRA', 'fuente' => 'BCRA — Central de Deudores', 'fecha' => $comprador->validacion_cuit_en ?? $fecha];
        }
        if ($tomas !== []) {
            $facts['operaciones_registradas'] = ['valor' => count($tomas), 'descripcion' => 'Tomas registradas para este comprador en este establecimiento: '.count($tomas), 'fuente' => 'TROPILLA — contratos de este establecimiento', 'fecha' => $fecha];
        }
        if ($comprador && count($tomas) >= 4) {
            $mediana = (float) collect(array_slice($tomas, 1))->median();
            if ($mediana > 0) {
                $facts['compra_vs_mediana'] = ['valor' => round($tomas[0] / $mediana, 2), 'descripcion' => 'La toma activa más reciente equivale a '.round($tomas[0] / $mediana, 2).' veces la mediana de las tres o más tomas anteriores (kg)', 'fuente' => 'TROPILLA — tomas registradas', 'fecha' => $fecha];
            }
        }
        $rules = DB::table('contraparte_reglas')->where('activa', true)->orderBy('codigo')->get();
        $rank = ['verde' => 0, 'amarillo' => 1, 'rojo' => 2];
        $semaforo = 'verde';
        $senales = [];
        foreach ($rules as $rule) {
            $fact = $facts[$rule->senal] ?? null;
            if (! $fact || $fact['valor'] === null || ! $this->cumple($fact['valor'], $rule->operador, (float) $rule->umbral)) {
                continue;
            }
            if (! isset($senales[$rule->senal]) || ($rank[$rule->severidad] ?? 0) > ($rank[$senales[$rule->senal]['severidad']] ?? 0)) {
                $senales[$rule->senal] = ['id' => $rule->codigo, 'codigo' => $rule->senal, 'severidad' => $rule->severidad, 'descripcion' => $fact['descripcion'],
                    'valor' => $fact['valor'], 'umbral' => (float) $rule->umbral, 'version_regla' => $rule->version, 'fuente' => $fact['fuente'], 'fecha' => $fact['fecha']];
            }
            if (($rank[$rule->severidad] ?? 0) > ($rank[$semaforo] ?? 0)) {
                $semaforo = $rule->severidad;
            }
        }
        if (($bcra['estado'] ?? null) !== 'ok') {
            $senales['fuente_no_disponible'] = ['id' => 'fuente_no_disponible', 'codigo' => 'fuente_no_disponible', 'severidad' => 'informativa',
                'descripcion' => 'La fuente pública no entregó datos utilizables; el semáforo queda incompleto. Esto no indica ausencia de deudas.', 'valor' => null, 'fuente' => 'BCRA — Central de Deudores', 'fecha' => $bcra['consultado_en'] ?? $fecha];
            if ($semaforo === 'verde') {
                $semaforo = 'amarillo';
            }
        }
        $senales = array_values($senales);

        return ['nivel' => $semaforo, 'completo' => ($bcra['estado'] ?? null) === 'ok', 'reglas_version' => (int) ($rules->max('version') ?? 1), 'senales' => $senales,
            'cobertura' => ['Tomas registradas; no incluye compras u ofertas realizadas fuera de TROPILLA.', 'TROPILLA no tiene un módulo de reclamos comerciales.', 'La antigüedad oficial del CUIT no está disponible mientras ARCA no tenga credenciales.'],
            'resumen' => $this->resumir($senales, $semaforo)];
    }

    private function cumple(float|int $valor, string $operador, float $umbral): bool
    {
        return match ($operador) {
            '>=' => $valor >= $umbral, '<=' => $valor <= $umbral, '==' => $valor == $umbral, '>' => $valor > $umbral, '<' => $valor < $umbral, default => false
        };
    }

    private function resumir(array $senales, string $nivel): array
    {
        if ($senales === []) {
            return ['texto' => 'No surgieron señales con estas reglas en la información consultada. Las fuentes cubren solo los datos que publican y esta consulta no determina la solvencia.', 'proveedor' => 'fallback', 'senales_ids' => []];
        }
        $fallback = implode(' ', array_map(fn ($s) => $s['descripcion'].'. Fuente: '.$s['fuente'].'; fecha: '.$s['fecha'].'.', $senales));
        $fallback .= ' El semáforo informa señales y no recomienda ni bloquea una operación.';
        try {
            $ia = app(Extractor::class);
            $ids = array_column($senales, 'id');
            $resultado = $ia->extraer([], 'Redactá un resumen breve en español rioplatense usando exclusivamente estas señales, sin inferencias ni acusaciones. Mencioná hechos presentes y devolvé texto y senales_ids como subconjunto de los IDs provistos. No interpretes el nivel como recomendación. Nivel informativo: '.$nivel.'. Señales: '.json_encode($senales, JSON_UNESCAPED_UNICODE), [
                'type' => 'object', 'properties' => ['texto' => ['type' => 'string'], 'senales_ids' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['texto', 'senales_ids'], 'additionalProperties' => false,
            ], 'Resumís señales concretas sobre contrapartes comerciales. No agregás inferencias, acusaciones ni hechos que no estén textualmente en la entrada. No recomendás operar ni bloquear.');
            $texto = $resultado->salida;
            if (! is_string($texto['texto'] ?? null) || ! is_array($texto['senales_ids'] ?? null) || array_diff($texto['senales_ids'], $ids)) {
                return ['texto' => $fallback, 'proveedor' => 'fallback', 'senales_ids' => $ids];
            }

            return ['texto' => $texto['texto'], 'proveedor' => $resultado->proveedor, 'senales_ids' => array_values($texto['senales_ids'])];
        } catch (\Throwable) {
            return ['texto' => $fallback, 'proveedor' => 'fallback', 'senales_ids' => array_column($senales, 'id')];
        }
    }
}
