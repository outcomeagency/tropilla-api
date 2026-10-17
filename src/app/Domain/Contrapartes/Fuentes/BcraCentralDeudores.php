<?php

namespace App\Domain\Contrapartes\Fuentes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * BCRA — Central de Deudores del Sistema Financiero (API pública, sin credenciales): situación actual por entidad, historial de
 * 24 meses y cheques rechazados. Los montos vienen en miles de pesos. La API responde 404 cuando no hay registros para el CUIT:
 * eso se informa como `sin_datos`, que **no** significa "sin deudas" (solo cubre lo informado por entidades financieras).
 */
final class BcraCentralDeudores implements FuenteContraparte
{
    public const URL = 'https://api.bcra.gob.ar/CentralDeDeudores/v1.0/';

    public const SITUACIONES = [
        1 => 'Situación 1 — normal',
        2 => 'Situación 2 — seguimiento especial / riesgo bajo',
        3 => 'Situación 3 — con problemas / riesgo medio',
        4 => 'Situación 4 — alto riesgo de insolvencia / riesgo alto',
        5 => 'Situación 5 — irrecuperable',
        6 => 'Situación 6 — irrecuperable por disposición técnica',
    ];

    public function codigo(): string
    {
        return 'bcra_central_deudores';
    }

    public function descripcion(): array
    {
        return [
            'codigo' => $this->codigo(),
            'nombre' => 'BCRA — Central de Deudores',
            'url' => self::URL,
            'descripcion' => 'Deudas informadas por bancos y otras entidades financieras (situación, monto y atraso), historial de 24 meses y cheques rechazados.',
            'cobertura' => 'Solo entidades que informan al BCRA. No incluye deudas comerciales, con proveedores, consignatarias ni juicios.',
            'configurada' => (bool) config('tropilla.contrapartes.bcra_habilitado'),
            'como_corregir' => 'El titular corrige un dato reclamando ante la entidad que lo informó; el BCRA publica lo que la entidad reporta.',
        ];
    }

    public function consultar(string $cuit): array
    {
        $base = ['codigo' => $this->codigo(), 'nombre' => 'BCRA — Central de Deudores', 'url' => self::URL];
        if (! config('tropilla.contrapartes.bcra_habilitado')) {
            return $base + ['estado' => 'no_configurada', 'consultado_en' => null, 'mensaje' => 'La consulta al BCRA está deshabilitada en este entorno.', 'datos' => null];
        }

        $clave = "contrapartes:bcra:{$cuit}";
        if (is_array($previo = Cache::get($clave))) {
            return $previo;
        }
        $resultado = (function () use ($cuit, $base) {
            try {
                $actual = $this->get("/Deudas/{$cuit}");
                $historial = $this->get("/Deudas/Historicas/{$cuit}");
                $cheques = $this->get("/Deudas/ChequesRechazados/{$cuit}");
            } catch (RuntimeException|ConnectionException) {
                return $base + ['estado' => 'error', 'consultado_en' => now()->toIso8601String(),
                    'mensaje' => 'El BCRA no respondió. No pudimos consultar esta fuente: probá de nuevo más tarde.', 'datos' => null];
            }
            $vacio = $actual === null && $historial === null && $cheques === null;

            return $base + [
                'estado' => $vacio ? 'sin_datos' : 'ok',
                'consultado_en' => now()->toIso8601String(),
                'mensaje' => $vacio ? 'El BCRA no tiene registros informados para este CUIT. Eso no significa que no tenga deudas: solo cubre entidades financieras.' : 'Datos informados por entidades financieras al BCRA.',
                'datos' => $vacio ? null : [
                    'denominacion' => $actual['denominacion'] ?? $historial['denominacion'] ?? $cheques['denominacion'] ?? null,
                    'actual' => $this->actual($actual),
                    'historial' => $this->historial($historial),
                    'cheques' => $this->cheques($cheques),
                ],
            ];
        })();
        // Un error no se cachea: el próximo intento vuelve a consultar.
        if ($resultado['estado'] !== 'error') {
            Cache::put($clave, $resultado, now()->addHours((int) config('tropilla.contrapartes.cache_horas')));
        }

        return $resultado;
    }

    /** @return array<string, mixed>|null `results` o null si la API respondió 404 (sin registros). */
    private function get(string $ruta): ?array
    {
        /** @var Response $r */
        $r = Http::connectTimeout(5)->timeout((int) config('tropilla.contrapartes.timeout_segundos'))->acceptJson()
            ->retry((int) config('tropilla.contrapartes.reintentos'), 800, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->get(rtrim((string) config('tropilla.contrapartes.bcra_url'), '/').$ruta);
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->successful() || ! is_array($r->json('results'))) {
            throw new RuntimeException("BCRA {$ruta}: HTTP {$r->status()}");
        }

        return $r->json('results');
    }

    /** @return array<string, mixed>|null */
    private function actual(?array $r): ?array
    {
        $periodo = $r['periodos'][0] ?? null;
        if ($periodo === null) {
            return null;
        }
        $entidades = array_map(fn ($e) => [
            'entidad' => (string) ($e['entidad'] ?? ''),
            'situacion' => (int) ($e['situacion'] ?? 0),
            'situacion_descripcion' => self::SITUACIONES[(int) ($e['situacion'] ?? 0)] ?? 'Situación sin descripción',
            'monto_miles' => (float) ($e['monto'] ?? 0),
            'dias_atraso' => isset($e['diasAtrasoPago']) ? (int) $e['diasAtrasoPago'] : null,
            'refinanciaciones' => (bool) ($e['refinanciaciones'] ?? false),
            'situacion_juridica' => (bool) ($e['situacionJuridica'] ?? false),
            'proceso_judicial' => (bool) ($e['procesoJud'] ?? false),
            'en_revision' => (bool) ($e['enRevision'] ?? false),
        ], $periodo['entidades'] ?? []);

        return ['periodo' => self::periodo((string) ($periodo['periodo'] ?? '')), 'entidades' => $entidades,
            'peor_situacion' => $entidades === [] ? null : max(array_column($entidades, 'situacion')),
            'monto_total_miles' => array_sum(array_column($entidades, 'monto_miles'))];
    }

    /** @return array<string, mixed>|null Resumen mensual: peor situación y monto total por período (más nuevo primero). */
    private function historial(?array $r): ?array
    {
        if (empty($r['periodos'])) {
            return null;
        }
        $periodos = array_map(function ($p) {
            $sits = array_map(fn ($e) => (int) ($e['situacion'] ?? 0), $p['entidades'] ?? []);

            return ['periodo' => self::periodo((string) ($p['periodo'] ?? '')), 'peor_situacion' => $sits === [] ? null : max($sits),
                'entidades' => count($sits), 'monto_total_miles' => array_sum(array_map(fn ($e) => (float) ($e['monto'] ?? 0), $p['entidades'] ?? []))];
        }, $r['periodos']);

        return ['periodos' => $periodos, 'meses_con_situacion_2_o_mas' => count(array_filter($periodos, fn ($p) => ($p['peor_situacion'] ?? 0) >= 2))];
    }

    /** @return array<string, mixed>|null */
    private function cheques(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $causales = [];
        foreach ($r['causales'] ?? [] as $c) {
            $detalle = [];
            foreach ($c['entidades'] ?? [] as $ent) {
                foreach ($ent['detalle'] ?? [] as $d) {
                    $detalle[] = ['entidad' => (string) ($ent['entidad'] ?? ''), 'numero' => (string) ($d['nroCheque'] ?? ''), 'fecha_rechazo' => $d['fechaRechazo'] ?? null,
                        'monto' => (float) ($d['monto'] ?? 0), 'fecha_pago' => $d['fechaPago'] ?? null, 'fecha_pago_multa' => $d['fechaPagoMulta'] ?? null,
                        'estado_multa' => $d['estadoMulta'] ?? null, 'en_revision' => (bool) ($d['enRevision'] ?? false), 'proceso_judicial' => (bool) ($d['procesoJud'] ?? false)];
                }
            }
            $causales[] = ['causal' => (string) ($c['causal'] ?? 'Sin causal informada'), 'cantidad' => count($detalle), 'detalle' => $detalle];
        }
        $todos = array_merge(...array_map(fn ($c) => $c['detalle'], $causales ?: [['detalle' => []]]));

        return ['total' => count($todos), 'sin_pago_informado' => count(array_filter($todos, fn ($d) => $d['fecha_pago'] === null)), 'causales' => $causales];
    }

    /** "202608" → "2026-08". */
    private static function periodo(string $p): string
    {
        return strlen($p) === 6 ? substr($p, 0, 4).'-'.substr($p, 4, 2) : $p;
    }
}
