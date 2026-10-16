<?php

namespace App\Domain\Contratos\Actions;

use App\Domain\Comercial\Services\Compromisos;
use App\Domain\Contratos\Sobres;
use App\Domain\Contratos\Terminos;
use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Gotenberg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Contrato a futuro con token (TPA-56, functional/01 §10 quater). Borrador editable → publicar (versión 1 de términos hasheada y
 * encadenada + PDF en Documentos) → nuevas versiones de términos con motivo (mortalidad/faltante recorta prorrata). Respeta el
 * compromiso de la hacienda: lo que está en oferta, en otro contrato o vendido no se tokeniza (409 `comprometido`).
 * Con tomas vigentes, la economía del trato (precio de referencia, descuento, anticipo, kg por token) queda fija.
 */
final class GestionarContrato
{
    private const ECONOMIA = ['precio_referencia_kg', 'descuento_pct', 'anticipo_pct', 'kg_por_token', 'moneda'];

    public function __construct(private readonly GestionarTomas $tomas, private readonly RegistrarAuditoria $auditoria, private readonly RegistrarDocumentoVersion $documentos) {}

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function calcular(Establecimiento $est, array $in): array
    {
        [$lote, $cabezas] = $this->lote($est, $in);

        return Terminos::calcular($in, $lote->especie_codigo, $this->categoria($lote), $cabezas, $est->id);
    }

    /** @param array<string, mixed> $in */
    public function crear(Establecimiento $est, User $u, array $in): string
    {
        return DB::transaction(function () use ($est, $u, $in) {
            Cadena::bloquearDeEstablecimiento($est->id);
            [$lote, $cabezas] = $this->lote($est, $in);
            $t = Terminos::calcular($in, $lote->especie_codigo, $this->categoria($lote), $cabezas, $est->id);
            $this->validarTerminos($t);
            $id = (string) Str::uuid7();
            DB::table('contratos_futuro')->insert(['id' => $id, 'establecimiento_id' => $est->id, 'lote_id' => $lote->id, 'codigo_publico' => self::codigo(),
                'estado' => 'borrador', ...$this->cabecera($t), 'terminos_borrador' => json_encode($t), 'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->auditoria->handle($est->id, $u->id, 'contrato', $id, 'crear', null, $t);

            return $id;
        });
    }

    public function publicar(Establecimiento $est, User $u, object $c): void
    {
        if ($c->estado !== 'borrador') {
            throw ApiException::reglaBloqueante(__('El contrato ya fue publicado.'));
        }
        if (! $u->email_verificado_en) {
            throw new ApiException(403, 'email_no_verificado', __('Verificá tu email para publicar.'));
        }
        $t = json_decode($c->terminos_borrador, true);
        $pdf = $this->pdf($est, $c, $t, 1);
        DB::transaction(function () use ($est, $u, $c, $t, $pdf) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            DB::table('contratos_futuro')->where('id', $c->id)->lockForUpdate()->first();
            $this->comprometer($est, $c, (int) $t['cabezas']);
            $this->version($cadena, $c, $u, $t, 1, null);
            DB::table('contratos_futuro')->where('id', $c->id)->update(['estado' => 'publicado', 'publicado_en' => now(), 'updated_at' => now()]);
            $this->documento($est, $u, $c->id, $pdf, 1, null);
            $this->auditoria->handle($est->id, $u->id, 'contrato', $c->id, 'publicar', null, ['codigo' => $c->codigo_publico]);
        });
    }

    /**
     * Nueva versión de términos (o edición del borrador). `simular` devuelve los términos y el recorte sin guardar.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>|null
     */
    public function terminos(Establecimiento $est, User $u, object $c, array $in, bool $simular): ?array
    {
        if (in_array($c->estado, ['entregado', 'liquidado', 'cancelado', 'incumplido'], true)) {
            throw ApiException::reglaBloqueante(__('El contrato está cerrado.'));
        }
        $base = json_decode($c->terminos_borrador, true);
        $in = [...$base, ...array_filter($in, fn ($v) => $v !== null)];
        $in['cabezas'] ??= $base['cabezas'];
        $t = Terminos::calcular($in, $c->especie_codigo, $c->categoria_codigo, (int) $in['cabezas'], $est->id);
        $this->validarTerminos($t);
        $activas = DB::table('tomas')->where('contrato_id', $c->id)->whereIn('estado', GestionarTomas::ACTIVAS)->get(['id', 'tokens_vigentes', 'estado', 'created_at']);
        if ($activas->isNotEmpty()) {
            foreach (self::ECONOMIA as $k) {
                if ((string) $t[$k] !== (string) $base[$k]) {
                    throw ApiException::reglaBloqueante(__('Con tomas vigentes no se cambia la economía del trato (:k).', ['k' => $k]), [$k => [(string) $base[$k]]]);
                }
            }
        }
        $plan = Terminos::recorte($activas->all(), (int) $t['tokens_totales']);
        if ($simular) {
            return ['terminos' => $t, 'recorte' => $plan];
        }
        if ($c->estado === 'borrador') {
            $this->lote($est, ['lote_id' => $c->lote_id, 'cabezas' => $t['cabezas']]);
            DB::table('contratos_futuro')->where('id', $c->id)->update([...$this->cabecera($t), 'terminos_borrador' => json_encode($t), 'row_version' => $c->row_version + 1, 'updated_at' => now()]);

            return null;
        }
        if (trim((string) ($in['motivo'] ?? '')) === '') {
            throw ApiException::validacion(__('Un cambio de términos publicado lleva motivo.'), ['motivo' => [__('Obligatorio.')]]);
        }
        $version = $c->version_vigente + 1;
        $pdf = $this->pdf($est, $c, $t, $version);
        DB::transaction(function () use ($est, $u, $c, $t, $plan, $in, $version, $pdf) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $actual = DB::table('contratos_futuro')->where('id', $c->id)->lockForUpdate()->first();
            if ((int) $t['cabezas'] > (int) $actual->cabezas) {
                $this->comprometer($est, $actual, (int) $t['cabezas']);
            } else {
                $this->recortarAnimales($c->id, (int) $t['cabezas']);
            }
            $this->version($cadena, $actual, $u, $t, $version, $in['motivo']);
            // Primero el recorte (para que los comprometidos entren en los nuevos totales: CHECK), después la cabecera.
            $this->tomas->aplicarRecorte($actual, $cadena, $plan, $u, $in['motivo']);
            $this->tomas->recalcularContrato($c->id);
            DB::table('contratos_futuro')->where('id', $c->id)->update([...$this->cabecera($t), 'terminos_borrador' => json_encode($t), 'updated_at' => now()]);
            $this->tomas->recalcularContrato($c->id);
            $this->documento($est, $u, $c->id, $pdf, $version, $in['motivo']);
        });

        return null;
    }

    public function cancelar(Establecimiento $est, User $u, object $c, string $motivo): void
    {
        $this->cerrar($est, $u, $c, 'cancelado', $motivo, ['borrador', 'publicado', 'parcialmente_tomado', 'completo']);
    }

    public function incumplido(Establecimiento $est, User $u, object $c, string $motivo): void
    {
        $this->cerrar($est, $u, $c, 'incumplido', $motivo, ['publicado', 'parcialmente_tomado', 'completo', 'entregado']);
    }

    /** @param list<string> $desde */
    private function cerrar(Establecimiento $est, User $u, object $c, string $estado, string $motivo, array $desde): void
    {
        if (! in_array($c->estado, $desde, true)) {
            throw ApiException::reglaBloqueante(__('No se puede pasar de :a a :b.', ['a' => $c->estado, 'b' => $estado]));
        }
        DB::transaction(function () use ($est, $u, $c, $estado, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $actual = DB::table('contratos_futuro')->where('id', $c->id)->lockForUpdate()->first();
            // Reservas sin confirmar se liberan; los anticipos confirmados quedan a devolver (fuera de la app, confirmado por ambos).
            foreach (DB::table('tomas')->where('contrato_id', $c->id)->whereIn('estado', GestionarTomas::ACTIVAS)->get() as $t) {
                if ($t->estado === 'confirmada') {
                    DB::table('tomas')->where('id', $t->id)->update(['anticipo_a_devolver' => $t->anticipo_monto, 'updated_at' => now()]);
                    $this->tomas->movimiento($cadena, $t->id, $estado, ['motivo' => $motivo, 'anticipo_a_devolver' => (string) $t->anticipo_monto], $u->id, 'productor');
                } else {
                    DB::table('tomas')->where('id', $t->id)->update(['estado' => 'liberada', 'tokens_vigentes' => 0, 'vence_en' => null, 'updated_at' => now()]);
                    $this->tomas->movimiento($cadena, $t->id, 'liberada', ['motivo' => $motivo], $u->id, 'productor');
                }
            }
            DB::table('contratos_futuro')->where('id', $c->id)->update(['estado' => $estado, 'motivo_estado' => $motivo, 'updated_at' => now()]);
            DB::table('contrato_animales')->where('contrato_id', $c->id)->delete(); // libera el compromiso
            $this->auditoria->handle($est->id, $u->id, 'contrato', $c->id, $estado, ['estado' => $actual->estado], ['estado' => $estado], $motivo);
        });
    }

    /** @param array<string, mixed> $in @return array{0: object, 1: int} lote y cabezas (≤ libres del lote) */
    private function lote(Establecimiento $est, array $in): array
    {
        $lote = DB::table('lotes')->where('id', $in['lote_id'] ?? null)->where('establecimiento_id', $est->id)->whereNull('archivado_en')->first()
            ?? throw ApiException::validacion(__('El lote no existe.'), ['lote_id' => [__('No existe en el establecimiento.')]]);
        $total = $this->cabezasLote($lote->id);
        $cabezas = (int) ($in['cabezas'] ?? $total);
        if ($cabezas < 1 || $cabezas > $total) {
            throw ApiException::validacion(__('El lote tiene :n cabezas.', ['n' => $total]), ['cabezas' => [__('Entre 1 y :n.', ['n' => $total])]]);
        }

        return [$lote, $cabezas];
    }

    private function cabezasLote(string $loteId): int
    {
        return DB::table('animales_estado')->where('lote_id', $loteId)->where('estado', 'activo')->count()
            + (int) DB::table('lote_conteos')->where('lote_id', $loteId)->sum('cabezas');
    }

    private function categoria(object $lote): string
    {
        $porCat = DB::table('animales_estado')->where('lote_id', $lote->id)->where('estado', 'activo')->groupBy('categoria_codigo')
            ->selectRaw('categoria_codigo as c, count(*) as n')->pluck('n', 'c')->all();
        foreach (DB::table('lote_conteos')->where('lote_id', $lote->id)->get() as $x) {
            $porCat[$x->categoria_codigo] = ($porCat[$x->categoria_codigo] ?? 0) + $x->cabezas;
        }
        arsort($porCat);

        return (string) (array_key_first($porCat) ?? throw ApiException::validacion(__('El lote está vacío.'), ['lote_id' => [__('Sin hacienda.')]]));
    }

    /** Compromete la hacienda del lote para el contrato (individuales libres + cabezas anónimas no comprometidas). 409 si no alcanza. */
    private function comprometer(Establecimiento $est, object $c, int $cabezas): void
    {
        $individuales = DB::table('animales_estado')->where('lote_id', $c->lote_id)->where('estado', 'activo')->orderBy('animal_id')->pluck('animal_id')->all();
        $comp = Compromisos::deAnimales($individuales);
        $propios = DB::table('contrato_animales')->where('contrato_id', $c->id)->pluck('animal_id')->all();
        $libres = array_values(array_filter($individuales, fn ($a) => ! isset($comp[$a]) || in_array($a, $propios, true)));
        $anonimas = (int) DB::table('lote_conteos')->where('lote_id', $c->lote_id)->sum('cabezas');
        $otrosContratos = (int) DB::table('contratos_futuro')->where('lote_id', $c->lote_id)->where('id', '<>', $c->id)
            ->whereIn('estado', ['publicado', 'parcialmente_tomado', 'completo'])->sum('cabezas');
        $anonLibres = max(0, $anonimas - Compromisos::cabezasComprometidasDeLote($c->lote_id) - $otrosContratos);
        if (count($libres) + $anonLibres < $cabezas) {
            throw new ApiException(409, 'comprometido', __('La hacienda del lote ya está en una oferta activa, en otro contrato o vendida: retirá la oferta primero.'),
                ['cabezas' => [(string) (count($libres) + $anonLibres).' libres']]);
        }
        DB::table('contrato_animales')->where('contrato_id', $c->id)->delete();
        foreach (array_slice($libres, 0, $cabezas) as $a) {
            DB::table('contrato_animales')->insert(['contrato_id' => $c->id, 'animal_id' => $a]);
        }
    }

    private function recortarAnimales(string $contratoId, int $cabezas): void
    {
        $ids = DB::table('contrato_animales as ca')->leftJoin('animales_estado as a', 'a.animal_id', '=', 'ca.animal_id')->where('ca.contrato_id', $contratoId)
            ->orderByRaw("a.estado = 'activo' DESC")->orderBy('ca.animal_id')->pluck('ca.animal_id')->all();
        DB::table('contrato_animales')->where('contrato_id', $contratoId)->whereIn('animal_id', array_slice($ids, $cabezas))->delete();
    }

    /** @param array<string, mixed> $t */
    private function version(Cadena $cadena, object $c, User $u, array $t, int $version, ?string $motivo): void
    {
        $anterior = $version > 1 ? DB::table('contrato_versiones')->where('contrato_id', $c->id)->where('version', $version - 1)->value('hash') : null;
        $r = Sobres::registrar($cadena, 'tropilla.contrato_version/1', 'contrato_version', [
            'id' => (string) Str::uuid7(), 'contrato_id' => $c->id, 'establecimiento_id' => $c->establecimiento_id, 'codigo' => $c->codigo_publico,
            'version' => $version, 'hash_anterior' => $anterior, 'motivo' => $motivo, 'terminos' => $t, 'autor_id' => $u->id, 'origen' => 'web',
        ]);
        DB::table('contrato_versiones')->insert(['id' => $r['sobre']->id, 'contrato_id' => $c->id, 'version' => $version, 'motivo' => $motivo, 'terminos' => json_encode($t),
            'sobre' => Sobres::json($r['sobre']), 'hash' => $r['huella'], 'registro_integridad_id' => $r['registro_id'], 'autor_id' => $u->id, 'created_at' => now()]);
        DB::table('contratos_futuro')->where('id', $c->id)->update(['version_vigente' => $version]);
    }

    /** @param array<string, mixed> $t */
    private function validarTerminos(array $t): void
    {
        if ($t['tokens_totales'] < 1) {
            throw ApiException::validacion(__('Con esos datos no alcanza para un token.'), ['peso_vivo_estimado_kg' => [__('Kg al gancho menores que un token.')]]);
        }
        if ($t['fecha_entrega'] <= now()->toDateString()) {
            throw ApiException::validacion(__('La entrega es futura.'), ['fecha_entrega' => [__('Debe ser posterior a hoy.')]]);
        }
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function cabecera(array $t): array
    {
        return ['especie_codigo' => $t['especie'], 'categoria_codigo' => $t['categoria'], 'cabezas' => $t['cabezas'], 'kg_por_token' => $t['kg_por_token'],
            'tokens_totales' => $t['tokens_totales'], 'precio_especial_kg' => $t['precio_especial_kg'], 'anticipo_pct' => $t['anticipo_pct'],
            'anticipo_por_token' => $t['anticipo_por_token'], 'fecha_entrega' => $t['fecha_entrega'], 'toma_vencimiento_horas' => $t['toma_vencimiento_horas'],
            'tolerancia_pct' => $t['tolerancia_pct'], 'moneda' => $t['moneda']];
    }

    /** @param array<string, mixed> $t */
    private function pdf(Establecimiento $est, object $c, array $t, int $version): ?string
    {
        try {
            return Gotenberg::html(view('pdf.contrato', ['c' => $c, 't' => $t, 'version' => $version, 'establecimiento' => $est->nombre,
                'url' => config('tropilla.web_url').'/c/'.$c->codigo_publico])->render());
        } catch (Throwable $e) {
            Log::warning('contrato: no se pudo generar el PDF', ['contrato' => $c->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function documento(Establecimiento $est, User $u, string $contratoId, ?string $pdf, int $version, ?string $motivo): void
    {
        if ($pdf === null) {
            return;
        }
        $sha = app(GuardarArchivo::class)->handle($pdf, 'application/pdf', $u);
        $archivos = [['sha256' => $sha, 'bytes' => strlen($pdf), 'mime' => 'application/pdf']];
        $c = DB::table('contratos_futuro')->where('id', $contratoId)->first();
        $titulo = "Contrato a futuro {$c->codigo_publico} — v{$version}";
        if ($c->documento_id === null) {
            $r = $this->documentos->handle($est, $u, ArmarSobreDocumentoWeb::alta($est, $u, ['tipo' => 'contrato_futuro_pdf', 'privacidad' => 'comercial', 'titulo' => $titulo,
                'emitido_en' => now($est->zona_horaria)->toDateString(), 'vinculos' => [['tipo' => 'contrato', 'id' => $contratoId]], 'archivos' => $archivos]), ['canal' => 'web']);
            DB::table('contratos_futuro')->where('id', $contratoId)->update(['documento_id' => $r->documentoId]);

            return;
        }
        $doc = DB::table('documentos')->where('id', $c->documento_id)->first();
        $this->documentos->handle($est, $u, ArmarSobreDocumentoWeb::nuevaVersion($doc, $u, ['accion' => 'nueva_version', 'motivo' => "Términos v{$version}: ".($motivo ?? ''),
            'titulo' => $titulo, 'archivos' => $archivos]), ['canal' => 'web']);
    }

    public static function codigo(): string
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        do {
            $c = '';
            foreach (str_split(random_bytes(10)) as $b) {
                $c .= $alfabeto[ord($b) % 32];
            }
        } while (DB::table('contratos_futuro')->where('codigo_publico', $c)->exists());

        return $c;
    }
}
