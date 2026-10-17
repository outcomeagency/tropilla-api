<?php

namespace App\Domain\Comercial\Actions;

use App\Domain\Comercial\Services\Compromisos;
use App\Domain\Comercial\SimuladorLiquidacion;
use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sanidad\Retiros;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Decimales as D;
use App\Support\Gotenberg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Venta/compra con las condiciones del trato (TPA-46, modelo-datos §9.2). Cada alta/corrección/anulación es una versión
 * **append-only** (`tropilla.operacion_version/1`, hasheada y encadenada) y la cabecera `operaciones` es su proyección.
 * La liquidación se calcula con la misma fórmula del simulador (TPA-45) sobre los valores reales; si el flete lo paga el
 * comprador no se descuenta. Genera el **resumen PDF** (Gotenberg) y lo guarda en Documentos con huella (`resumen_trato`,
 * privacidad comercial, vinculado a la operación). Marca el **compromiso «vendido»** de los animales del trato y cierra la
 * oferta de origen. Advierte retiro vigente y reactores (TPA-35); lo que está en un contrato a futuro → 409 `comprometido`.
 * Precios siempre privados: no viajan por sync ni a lo público.
 */
final class RegistrarOperacion
{
    public function __construct(
        private readonly SimuladorLiquidacion $simulador,
        private readonly RegistrarEnCadena $registrarEnCadena,
        private readonly RegistrarDocumentoVersion $documentos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array<string, mixed> $d OperacionInput validado */
    public function alta(Establecimiento $est, User $u, array $d): string
    {
        $id = strtolower($d['id'] ?? (string) Str::uuid7());

        return $this->registrar($est, $u, $id, null, 'alta', $d);
    }

    /** @param array<string, mixed> $d */
    public function corregir(Establecimiento $est, User $u, object $op, array $d): string
    {
        if ($op->estado === 'anulada') {
            throw ApiException::reglaBloqueante(__('La operación está anulada.'));
        }

        return $this->registrar($est, $u, $op->id, $op, 'correccion', $d);
    }

    public function anular(Establecimiento $est, User $u, object $op, string $motivo): string
    {
        if ($op->estado === 'anulada') {
            return $op->id;
        }

        return DB::transaction(function () use ($est, $u, $op, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $prev = DB::table('operacion_versiones')->where('operacion_id', $op->id)->where('version', $op->version_vigente)->first();
            $sobre = $this->sobre(['id' => (string) Str::uuid7(), 'operacion_id' => $op->id, 'establecimiento_id' => $est->id, 'version' => $op->version_vigente + 1,
                'accion' => 'anulacion', 'hash_anterior' => $prev->hash, 'motivo' => $motivo, 'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'autor_id' => $u->id, 'origen' => 'web']);
            $this->insertarVersion($cadena, $sobre, json_decode($prev->trato, true), json_decode($prev->liquidacion, true), json_decode($prev->trazabilidad, true), null, $u);
            DB::table('operaciones')->where('id', $op->id)->update(['estado' => 'anulada', 'version_vigente' => $sobre->version, 'updated_at' => now()]);
            if ($op->documento_id && ($doc = DB::table('documentos')->where('id', $op->documento_id)->first()) && $doc->estado !== 'revocado') {
                $this->documentos->handle($est, $u, ArmarSobreDocumentoWeb::nuevaVersion($doc, $u, ['accion' => 'revocacion', 'motivo' => "Operación anulada: {$motivo}"]), ['canal' => 'web']);
            }
            $this->auditoria->handle($est->id, $u->id, 'operacion', $op->id, 'anular', null, null, $motivo);

            return $op->id;
        });
    }

    /** @param array<string, mixed> $d */
    private function registrar(Establecimiento $est, User $u, string $id, ?object $prev, string $accion, array $d): string
    {
        $t = $d['trato'];
        $modalidad = $t['modalidad'];
        $liq = $this->liquidar($est, $d, $t, $modalidad);
        $animalIds = $this->animales($est, $d);
        if ($d['tipo'] === 'venta') {
            $this->chequearHacienda($est, $animalIds, $d, $prev);
        }
        $version = $prev ? (int) $prev->version_vigente + 1 : 1;
        $refPrecio = isset($d['precio_referencia_id']) ? DB::table('precios_referencia')->where('id', $d['precio_referencia_id'])->where('establecimiento_id', $est->id)->first() : null;

        // PDF del trato antes de hashear: su SHA-256 va dentro del sobre.
        $pdf = null;
        if ($d['generar_pdf'] ?? true) {
            try {
                $pdf = Gotenberg::html(view('pdf.trato', ['op' => [
                    'id' => $id, 'version' => $version, 'tipo' => $d['tipo'], 'fecha' => $d['fecha'], 'especie' => $d['especie'], 'cantidad' => $d['cantidad'],
                    'categoria_nombre' => (string) DB::table('categorias')->where('codigo', $d['categoria'])->value('nombres->es'),
                    'establecimiento' => $est->nombre, 'moneda' => $liq['moneda'], 'trato' => $t, 'liquidacion' => $liq, 'trazabilidad' => $d['trazabilidad'] ?? [],
                ]])->render());
            } catch (Throwable $e) {
                Log::warning('operación: no se pudo generar el PDF del trato', ['operacion' => $id, 'error' => $e->getMessage()]);
            }
        }

        return DB::transaction(function () use ($est, $u, $id, $prev, $accion, $d, $t, $modalidad, $liq, $animalIds, $version, $refPrecio, $pdf) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $pdfSha = $pdf ? app(GuardarArchivo::class)->handle($pdf, 'application/pdf', $u) : null;
            $hashAnterior = $prev ? DB::table('operacion_versiones')->where('operacion_id', $id)->where('version', $prev->version_vigente)->value('hash') : null;
            $sobre = $this->sobre([
                'id' => (string) Str::uuid7(), 'operacion_id' => $id, 'establecimiento_id' => $est->id, 'version' => $version, 'accion' => $accion,
                'hash_anterior' => $hashAnterior, 'motivo' => $d['motivo'] ?? null, 'tipo' => $d['tipo'], 'fecha' => $d['fecha'], 'especie' => $d['especie'],
                'categoria' => $d['categoria'], 'cantidad' => $d['cantidad'], 'trato' => $t, 'liquidacion' => $liq, 'trazabilidad' => $d['trazabilidad'] ?? null,
                'evento_raiz_id' => $d['evento_raiz_id'] ?? null, 'contrato_id' => $d['contrato_id'] ?? null, 'proveedor_id' => $d['proveedor_id'] ?? null, 'pdf_sha256' => $pdfSha,
                'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'autor_id' => $u->id, 'origen' => 'web',
            ]);

            $cab = [
                'tipo' => $d['tipo'], 'estado' => 'registrada', 'version_vigente' => $version, 'evento_raiz_id' => $d['evento_raiz_id'] ?? null,
                'simulacion_id' => $d['simulacion_id'] ?? null, 'contrato_id' => $d['contrato_id'] ?? null, 'oferta_id' => $d['oferta_id'] ?? null,
                'proveedor_id' => $d['proveedor_id'] ?? null,
                'especie_codigo' => $d['especie'], 'categoria_codigo' => $d['categoria'], 'cantidad' => $d['cantidad'], 'fecha' => $d['fecha'],
                'contraparte' => $t['comprador'] ?? null, 'destino' => $t['destino'] ?? null, 'modalidad' => $modalidad, 'precio_kg' => $liq['precio_kg'],
                'moneda' => $liq['moneda'], 'neto_total' => $liq['neto_total'], 'neto_kg_vivo' => $liq['neto_kg_vivo'],
                'precio_referencia_id' => $refPrecio?->id,
                'diferencial_pct' => $refPrecio ? D::redondear(D::mul(D::div(D::sub($liq['precio_kg'], (string) $refPrecio->precio_kg), (string) $refPrecio->precio_kg), '100'), 2) : null,
                'updated_at' => now(),
            ];
            if ($prev === null) {
                DB::table('operaciones')->insert($cab + ['id' => $id, 'establecimiento_id' => $est->id, 'creado_por' => $u->id, 'created_at' => now()]);
            } else {
                DB::table('operaciones')->where('id', $id)->update($cab);
            }
            $this->insertarVersion($cadena, $sobre, $t, $liq, $d['trazabilidad'] ?? [], $pdfSha, $u);

            DB::table('operacion_animales')->where('operacion_id', $id)->delete();
            foreach ($animalIds as $a) {
                DB::table('operacion_animales')->insert(['operacion_id' => $id, 'animal_id' => $a]);
            }
            if (! empty($d['oferta_id'])) {
                DB::table('ofertas')->where('id', $d['oferta_id'])->where('establecimiento_id', $est->id)->whereIn('estado', ['publicada', 'reservada', 'borrador'])
                    ->update(['estado' => 'vendida', 'motivo_retiro' => 'Vendida (operación registrada).', 'retirada_en' => now(), 'updated_at' => now()]);
            }

            if ($pdfSha !== null) {
                $this->documento($est, $u, $id, $d, $version, $pdfSha, strlen((string) $pdf));
            }
            $this->auditoria->handle($est->id, $u->id, 'operacion', $id, $accion, null, ['version' => $version, 'tipo' => $d['tipo']], $d['motivo'] ?? null);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function liquidar(Establecimiento $est, array $d, array $t, string $modalidad): array
    {
        $l = $d['liquidacion'];
        $comprador = ($t['flete_a_cargo'] ?? 'vendedor') === 'comprador';
        $entradas = array_filter([
            'especie' => $d['especie'], 'iva_pct' => $l['iva_pct'] ?? null, 'flete' => $comprador ? '0' : ($l['flete'] ?? null),
            'arranque' => $l['arranque'] ?? null, 'seguro' => $l['seguro'] ?? null, 'comision_pct' => $l['comision_pct'] ?? ($t['comision_pct'] ?? null),
            'moneda' => $l['moneda'] ?? null,
            $modalidad => array_filter(['peso_bruto_kg' => $l['peso_bruto_kg'], 'precio_kg' => $l['precio_kg'],
                'desbaste_pct' => $modalidad === 'en_pie' ? ($l['desbaste_pct'] ?? ($t['desbaste_pct'] ?? null)) : null,
                'rendimiento_pct' => $modalidad === 'al_gancho' ? ($l['rendimiento_pct'] ?? null) : null], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null);
        $calc = $this->simulador->calcular($entradas, $est->id);
        $e = $calc['entradas'];

        return array_filter([
            'modalidad' => $modalidad, 'peso_bruto_kg' => $e[$modalidad]['peso_bruto_kg'], 'desbaste_pct' => $e[$modalidad]['desbaste_pct'] ?? null,
            'rendimiento_pct' => $e[$modalidad]['rendimiento_pct'] ?? null, 'precio_kg' => $e[$modalidad]['precio_kg'], 'iva_pct' => $e['iva_pct'],
            'flete' => $e['flete'], 'flete_a_cargo' => $comprador ? 'comprador' : 'vendedor', 'arranque' => $e['arranque'], 'seguro' => $e['seguro'],
            'comision_pct' => $e['comision_pct'], 'moneda' => $e['moneda'], ...$calc['resultado'][$modalidad],
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $d @return list<string> */
    private function animales(Establecimiento $est, array $d): array
    {
        $ids = $d['animal_ids'] ?? [];
        if ($ids === [] && ! empty($d['evento_raiz_id'])) {
            $ev = DB::table('eventos_vigentes')->where('evento_raiz_id', $d['evento_raiz_id'])->where('establecimiento_id', $est->id)->first()
                ?? throw ApiException::validacion(__('El evento no existe.'), ['evento_raiz_id' => [__('No existe en el establecimiento.')]]);
            $ids = DB::table('evento_animales')->where('evento_id', $ev->id)->pluck('animal_id')->all();
        }
        $ids = array_values(array_unique(array_map('strtolower', $ids)));
        if ($ids !== [] && DB::table('animales')->where('establecimiento_id', $est->id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ApiException::validacion(__('Hay animales que no son del establecimiento.'), ['animal_ids' => [__('Animales inexistentes.')]]);
        }
        sort($ids);

        return $ids;
    }

    /** @param list<string> $ids @param array<string, mixed> $d */
    private function chequearHacienda(Establecimiento $est, array $ids, array $d, ?object $prev): void
    {
        $activos = DB::table('animales_estado')->whereIn('animal_id', $ids)->where('estado', 'activo')->get(['animal_id', 'retiro_carne_hasta', 'reactor', 'identificacion_principal']);
        $comp = Compromisos::deAnimales($activos->pluck('animal_id')->all());
        $propios = $prev ? DB::table('operacion_animales')->where('operacion_id', $prev->id)->pluck('animal_id')->all() : [];
        foreach ($comp as $a => $estado) {
            if ($estado === 'en_contrato' && empty($d['contrato_id'])) {
                throw new ApiException(409, 'comprometido', __('Hay animales comprometidos en un contrato a futuro.'), ['animal_ids' => [$a]]);
            }
            if ($estado === 'vendido' && ! in_array($a, $propios, true)) {
                throw new ApiException(409, 'comprometido', __('Hay animales ya vendidos en otra operación.'), ['animal_ids' => [$a]]);
            }
        }
        if ($d['confirmar_advertencias'] ?? false) {
            return;
        }
        $fecha = $d['fecha'];
        $adv = [];
        foreach ($activos as $a) {
            if ($a->retiro_carne_hasta !== null && $a->retiro_carne_hasta >= $fecha) {
                $adv[] = ['codigo' => 'retiro_vigente', 'mensaje' => "El animal {$a->identificacion_principal} tiene retiro vigente hasta {$a->retiro_carne_hasta}.", 'detalle' => (object) ['animal_id' => $a->animal_id]];
            }
            if ($a->reactor && (($d['trato']['destino'] ?? null) !== 'faena')) {
                $adv[] = ['codigo' => 'reactor', 'mensaje' => "El animal {$a->identificacion_principal} es reactor: se sugiere la faena.", 'detalle' => (object) ['animal_id' => $a->animal_id]];
            }
        }
        if (! empty($d['lote_id']) && Retiros::loteVigente($d['lote_id'], $fecha)) {
            $adv[] = ['codigo' => 'retiro_vigente', 'mensaje' => 'El lote tiene retiro vigente.', 'detalle' => (object) ['lote_id' => $d['lote_id']]];
        }
        if ($adv !== []) {
            throw new ApiException(422, 'requiere_confirmacion', __('Hay advertencias: confirmá para registrar igual.'), [], $adv);
        }
    }

    /** @param array<string, mixed> $campos */
    private function sobre(array $campos): stdClass
    {
        $s = json_decode(json_encode(array_filter(['esquema' => 'tropilla.operacion_version/1', ...$campos], fn ($v) => $v !== null && $v !== [])), false);
        CanonicalJson::huella($s); // valida que sea canonicalizable (sin floats)

        return $s;
    }

    /**
     * @param  array<string, mixed>  $trato
     * @param  array<string, mixed>  $liq
     * @param  array<string, mixed>  $traz
     */
    private function insertarVersion(Cadena $cadena, stdClass $s, array $trato, array $liq, array $traz, ?string $pdfSha, User $u): void
    {
        $huella = CanonicalJson::huella($s);
        $registro = $this->registrarEnCadena->handle($cadena, 'operacion_version', $s->id, $huella);
        DB::table('operacion_versiones')->insert([
            'id' => $s->id, 'operacion_id' => $s->operacion_id, 'establecimiento_id' => $s->establecimiento_id, 'version' => $s->version, 'accion' => $s->accion,
            'motivo' => $s->motivo ?? null, 'trato' => json_encode($trato), 'liquidacion' => json_encode($liq), 'trazabilidad' => json_encode((object) $traz),
            'pdf_sha256' => $pdfSha, 'sobre' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'hash' => $huella,
            'hash_anterior' => $s->hash_anterior ?? null, 'registro_integridad_id' => $registro->id, 'autor_id' => $u->id, 'created_at' => Carbon::now(),
        ]);
    }

    /** @param array<string, mixed> $d */
    private function documento(Establecimiento $est, User $u, string $opId, array $d, int $version, string $sha, int $bytes): void
    {
        $titulo = 'Resumen del trato — '.$d['tipo'].' '.$d['cantidad'].' '.$d['categoria'].' '.$d['fecha'];
        $docId = DB::table('operaciones')->where('id', $opId)->value('documento_id');
        $archivo = [['sha256' => $sha, 'bytes' => $bytes, 'mime' => 'application/pdf']];
        if ($docId === null) {
            $r = $this->documentos->handle($est, $u, ArmarSobreDocumentoWeb::alta($est, $u, [
                'tipo' => 'resumen_trato', 'privacidad' => 'comercial', 'titulo' => $titulo, 'emitido_en' => $d['fecha'],
                'vinculos' => [['tipo' => 'operacion', 'id' => $opId]], 'archivos' => $archivo,
            ]), ['canal' => 'web']);
            DB::table('operaciones')->where('id', $opId)->update(['documento_id' => $r->documentoId]);

            return;
        }
        $doc = DB::table('documentos')->where('id', $docId)->first();
        $this->documentos->handle($est, $u, ArmarSobreDocumentoWeb::nuevaVersion($doc, $u, ['accion' => 'nueva_version', 'motivo' => "Operación corregida (v{$version}): ".($d['motivo'] ?? ''),
            'titulo' => $titulo, 'archivos' => $archivo]), ['canal' => 'web']);
    }
}
