<?php

namespace App\Domain\Clima\Acciones;

use App\Domain\Clima\FuentesEscenarios;
use App\Domain\Clima\UbicacionClima;
use App\Domain\Documentos\Borradores\Extraccion\Extractor;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GenerarEscenariosClimaticos
{
    public function __construct(private readonly FuentesEscenarios $fuentes) {}

    public function handle(Establecimiento $establecimiento): array
    {
        $ubicacion = UbicacionClima::resolver($establecimiento);
        if ($ubicacion === null) {
            return [];
        }
        $fuentes = [];
        foreach ([
            'nasa_power' => fn () => $this->fuentes->observaciones($ubicacion['lat'], $ubicacion['lon']),
            'modis_ndvi' => fn () => $this->fuentes->ndvi($ubicacion['lat'], $ubicacion['lon']),
            'noaa_enso' => fn () => $this->fuentes->enso(),
        ] as $key => $resolver) {
            try {
                $fuentes[$key] = $resolver();
            } catch (\Throwable $e) {
                $fuentes[$key] = ['estado' => 'sin_datos', 'mensaje' => $e->getMessage()];
            }
        }

        $lluvia = data_get($fuentes, 'nasa_power.serie.PRECTOTCORR', []);
        $valores = array_values(array_filter($lluvia, fn ($v) => is_numeric($v) && (float) $v >= 0));
        $lluviaPromedio = $valores === [] ? null : array_sum(array_slice($valores, -90)) / count(array_slice($valores, -90));
        $tmax = array_values(array_filter(data_get($fuentes, 'nasa_power.serie.T2M_MAX', []), fn ($v) => is_numeric($v) && (float) $v < 80));
        $humedad = array_values(array_filter(data_get($fuentes, 'nasa_power.serie.RH2M', []), fn ($v) => is_numeric($v) && (float) $v >= 0 && (float) $v <= 100));
        $observacionTermica = $tmax === [] ? 'NASA POWER no entregó temperatura máxima reciente legible.' : 'En los últimos datos diarios NASA POWER, la máxima llegó a '.number_format(max(array_slice($tmax, -30)), 1, ',', '.').' °C y la humedad relativa media reciente fue '.($humedad === [] ? 'sin dato' : number_format(array_sum(array_slice($humedad, -30)) / count(array_slice($humedad, -30)), 0, ',', '.').' %').'.';
        $ndvi = data_get($fuentes, 'modis_ndvi.respuesta.subset', []);
        $periodoEnso = data_get($fuentes, 'noaa_enso.periodos.0');
        $lecturasNdvi = [];
        foreach (is_array($ndvi) ? $ndvi : [] as $fila) {
            if (! is_array($fila) || ! str_contains(strtolower((string) ($fila['band'] ?? '')), 'ndvi') || ! is_array($fila['data'] ?? null)) {
                continue;
            }
            $pixeles = array_values(array_filter($fila['data'], fn ($v) => is_numeric($v) && (float) $v > -2000));
            if ($pixeles !== []) {
                $lecturasNdvi[] = ['fecha' => $fila['calendar_date'] ?? $fila['modis_date'] ?? null, 'valor' => round(array_sum($pixeles) / count($pixeles) * 0.0001, 3)];
            }
        }
        $ndviDisponible = $lecturasNdvi !== [];
        $ultimoNdvi = $ndviDisponible ? end($lecturasNdvi) : null;
        $cambioNdvi = count($lecturasNdvi) > 1 ? round($ultimoNdvi['valor'] - $lecturasNdvi[count($lecturasNdvi) - 2]['valor'], 3) : null;
        $alertas = DB::table('clima_alertas')->where('establecimiento_id', $establecimiento->id)->where('estado', 'activa')->where('hasta', '>=', now()->toDateString())
            ->get(['tipo', 'severidad', 'titulo', 'fuente', 'fuente_url', 'desde', 'hasta'])->map(fn ($a) => (array) $a)->all();
        $alertaSequia = collect($alertas)->firstWhere('tipo', 'sequia');
        $alertaExceso = collect($alertas)->firstWhere('tipo', 'lluvia');
        $alertaCalor = collect($alertas)->firstWhere('tipo', 'calor');
        $brief = DB::table('forraje_briefs')->where('establecimiento_id', $establecimiento->id)->orderByDesc('created_at')->value('resultado');
        $forraje = $brief ? json_decode($brief, true) : null;
        $deficitForrajero = collect($forraje['escenarios'] ?? [])->contains(fn ($s) => in_array($s['estado'] ?? '', ['parcial', 'sin_reservas'], true));
        $probEnso = $periodoEnso['probabilidades'] ?? null;
        $pronostico = $probEnso === null ? 'NOAA CPC no entregó probabilidades ENSO legibles; no hay probabilidad local disponible' :
            'NOAA CPC estima para '.$periodoEnso['temporada'].' El Niño '.$probEnso['el_nino_pct'].' %, neutral '.$probEnso['neutral_pct'].' % y La Niña '.$probEnso['la_nina_pct'].' %; estas chances del Pacífico no son probabilidad local de impacto';
        $fuentesActivas = array_values(array_filter(array_map(fn ($f) => $f['fuente'] ?? null, $fuentes)));
        // Señal ENSO de la temporada próxima: en el NEA, El Niño se asocia históricamente a lluvias por encima de lo normal en primavera-verano y La Niña a déficit.
        $umbralEnso = (int) config('tropilla.clima.enso_umbral_pct', 60);
        $ninoFuerte = $probEnso !== null && $probEnso['el_nino_pct'] >= $umbralEnso;
        $ninaFuerte = $probEnso !== null && $probEnso['la_nina_pct'] >= $umbralEnso;
        $textoNino = $ninoFuerte ? 'NOAA CPC asigna '.$probEnso['el_nino_pct'].' % a El Niño para '.$periodoEnso['temporada'].'. En el NEA, El Niño se asocia históricamente a lluvias por encima de lo normal en primavera y verano: conviene revisar con tiempo potreros bajos, caminos y reservas. ' : '';
        $textoNina = $ninaFuerte ? 'NOAA CPC asigna '.$probEnso['la_nina_pct'].' % a La Niña para '.$periodoEnso['temporada'].'. En el NEA, La Niña se asocia históricamente a lluvias por debajo de lo normal: conviene revisar reservas y carga con tiempo. ' : '';
        $escenarios = [
            $this->escenario('sequia', 'Sequía', $alertaSequia || $ninaFuerte ? 'amarillo' : 'gris', $textoNina.($alertaSequia ? 'TPA-70 registra una señal vigente para la zona entre '.$alertaSequia['desde'].' y '.$alertaSequia['hasta'].'. Fuente: '.$alertaSequia['fuente'].'. ' : 'No hay alerta TPA-70 de sequía vigente; esto no descarta riesgo futuro. ').'Lluvia diaria media de los últimos datos NASA POWER: '.($lluviaPromedio === null ? 'sin dato' : number_format($lluviaPromedio, 1, ',', '.').' mm/día').'. Horizonte exploratorio 1–6 meses; '.$pronostico.'.', $ubicacion['zona'], $fuentesActivas),
            $this->escenario('exceso_hidrico', 'Exceso hídrico', $alertaExceso || $ninoFuerte ? 'amarillo' : 'gris', $textoNino.($alertaExceso ? 'TPA-70 registra una alerta de lluvia para la zona entre '.$alertaExceso['desde'].' y '.$alertaExceso['hasta'].'. Fuente: '.$alertaExceso['fuente'].'. ' : 'No hay alerta TPA-70 de lluvia extrema vigente; esto no descarta riesgo futuro. ').'La señal zonal no estima anegamiento predial ni drenaje. Horizonte exploratorio 1–6 meses con incertidumbre alta.', $ubicacion['zona'], $fuentesActivas),
            $this->escenario('deficit_forrajero', 'Déficit forrajero', $deficitForrajero ? 'amarillo' : 'gris', ($deficitForrajero ? 'El último brief TPA-71 incluye escenarios con faltante de reservas; es una proyección de insumos declarados, no de disponibilidad real de pasto. ' : 'No hay un brief de forraje con déficit para contrastar; la señal queda indeterminada. ').($ultimoNdvi ? 'MODIS reportó NDVI '.number_format($ultimoNdvi['valor'], 3, ',', '.').' ('.($ultimoNdvi['fecha'] ?? 'fecha no disponible').')'.($cambioNdvi === null ? '' : '; cambio frente a la composición anterior '.($cambioNdvi >= 0 ? '+' : '').number_format($cambioNdvi, 3, ',', '.')).'. El NDVI no se interpreta como kilos de forraje.' : 'NDVI sin composición legible disponible.'), $ubicacion['zona'], $fuentesActivas),
            $this->escenario('estres_termico', 'Estrés térmico', $alertaCalor ? 'amarillo' : 'gris', ($alertaCalor ? 'TPA-70 registra una alerta de calor para la zona entre '.$alertaCalor['desde'].' y '.$alertaCalor['hasta'].'. Fuente: '.$alertaCalor['fuente'].'. ' : 'No hay alerta TPA-70 de calor vigente; esto no descarta riesgo futuro. ').$observacionTermica.' No hay pronóstico local de temperatura a 1–6 meses.', $ubicacion['zona'], $fuentesActivas),
        ];
        $plan = [
            ['accion' => 'Evaluar alternativas de pastaje o arrendamiento de campos', 'href' => '/campos', 'relacion' => 'TPA-74'],
            ['accion' => 'Consultar fletes disponibles para un eventual traslado', 'href' => '/fletes', 'relacion' => 'TPA-73'],
            ['accion' => 'Revisar reservas de forraje y alimentos', 'href' => '/forraje', 'relacion' => 'Forraje · proveedores TPA-82'],
            ['accion' => 'Evaluar una venta anticipada con compradores', 'href' => '/comercializacion/ofertas/nueva', 'relacion' => 'Venta anticipada'],
        ];
        $senales = array_map(fn ($s) => $s['titulo'].': '.$s['descripcion'], $escenarios);
        $resumen = implode(' ', $senales).' Son escenarios exploratorios y dependen de fuentes parciales; no son una certeza ni una indicación veterinaria.';
        $planTexto = 'Podés revisar primero disponibilidad y costo de pastaje, alimento y logística; si alguna alternativa resulta útil, comparala con la posibilidad de adelantar una venta. Ningún paso es obligatorio y las señales requieren contraste local.';
        $proveedor = 'fallback';
        try {
            $ia = app(Extractor::class)->extraer([], 'Redactá un análisis y un plan de respuesta orientativo, en español rioplatense, usando solo estas señales. No inventes probabilidades ni hechos, mantené incertidumbre, no incluyas recetas veterinarias, y dejá claro que el productor decide. El plan puede mencionar evaluar campos, fletes, alimento y venta anticipada sin ordenarlos como indicación: '.json_encode($senales, JSON_UNESCAPED_UNICODE), [
                'type' => 'object', 'properties' => ['resumen' => ['type' => 'string'], 'plan' => ['type' => 'string']], 'required' => ['resumen', 'plan'], 'additionalProperties' => false,
            ], 'Asistente para productores ganaderos. No reemplazás asesoramiento profesional ni veterinario. Solo reformulás señales presentes y opciones del ecosistema sin dar instrucciones obligatorias.');
            if ($this->textoSeguro($ia->salida['resumen'] ?? null) && $this->textoSeguro($ia->salida['plan'] ?? null)) {
                $resumen = $ia->salida['resumen'];
                $planTexto = $ia->salida['plan'];
                $proveedor = $ia->proveedor;
            }
        } catch (\Throwable) {
            // El resumen determinístico conserva las señales si la IA no está disponible.
        }
        $plan[0]['orientacion'] = $planTexto;
        $id = (string) Str::uuid7();
        DB::table('escenarios_climaticos')->insert(['id' => $id, 'establecimiento_id' => $establecimiento->id, 'creado_por' => null,
            'datos_fuente' => json_encode($fuentes), 'escenarios' => json_encode($escenarios), 'plan_respuesta' => json_encode($plan), 'resumen' => $resumen,
            'proveedor_ia' => $proveedor, 'zona' => $ubicacion['zona'], 'emitido_en' => now(), 'created_at' => now()]);

        return ['id' => $id, 'zona' => $ubicacion['zona'], 'emitido_en' => now()->toIso8601String(), 'horizonte_meses' => [1, 6], 'escenarios' => $escenarios,
            'plan_respuesta' => $plan, 'resumen' => $resumen, 'proveedor_ia' => $proveedor, 'fuentes' => $fuentes,
            'aviso' => 'Escenarios orientativos con incertidumbre. La persona productora decide; no son pronósticos garantizados ni recetas veterinarias.'];
    }

    private function escenario(string $codigo, string $titulo, string $nivel, string $descripcion, string $zona, array $fuentes): array
    {
        return ['codigo' => $codigo, 'titulo' => $titulo, 'nivel' => $nivel, 'descripcion' => $descripcion, 'horizonte_meses' => [1, 6], 'probabilidad' => null,
            'probabilidad_nota' => 'Sin probabilidad local disponible; NOAA publica probabilidades ENSO, no probabilidades de impacto para este campo.',
            'incertidumbre' => 'alta', 'fuentes' => $fuentes, 'fecha' => now()->toIso8601String(), 'zona' => $zona];
    }

    private function textoSeguro(mixed $texto): bool
    {
        return is_string($texto) && trim($texto) !== '' && mb_strlen($texto) <= 1600
            && ! preg_match('/\b(dosis|tratamiento|vacun(?:a|ar|ación)|antibiótico|medicamento|administrar|receta veterinaria)\b/iu', $texto);
    }
}
