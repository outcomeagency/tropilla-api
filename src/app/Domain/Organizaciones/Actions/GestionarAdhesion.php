<?php

namespace App\Domain\Organizaciones\Actions;

use App\Domain\Contratos\Sobres;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adhesión del productor a una organización con **consentimiento granular, versionado y revocable** (TPA-51). Cada otorgamiento,
 * cambio de alcances o revocación es un consentimiento **append-only** hasheado y encadenado en la cadena del **productor**
 * (`tropilla.consentimiento/1`, con `hash_anterior` de la versión previa). Todo `false` por defecto. Revocar = deja de compartir datos
 * nuevos; lo anterior queda con fecha de baja (duda 26). La adhesión es la proyección de la última versión.
 */
final class GestionarAdhesion
{
    public const ALCANCES = ['existencias_agregadas', 'cobertura_sanitaria', 'lotes_postulados', 'precios_agregados'];

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array{organizacion_id: string, numero_socio?: ?string, alcances: array<string, bool>, texto_version: string} $d */
    public function adherir(string $productorId, User $u, array $d, ?string $establecimientoAuditoria): string
    {
        return DB::transaction(function () use ($productorId, $u, $d, $establecimientoAuditoria) {
            $cadena = Cadena::bloquearDeProductor($productorId);
            $org = DB::table('organizaciones')->where('id', $d['organizacion_id'])->whereNull('archivado_en')->first() ?? throw ApiException::noEncontrado();
            $a = DB::table('adhesiones')->where('productor_id', $productorId)->where('organizacion_id', $org->id)->lockForUpdate()->first();
            if ($a && $a->estado === 'activa') {
                throw new ApiException(409, 'ya_adherido', __('Ya estás adherido: modificá los alcances.'));
            }
            if ($a === null) {
                $id = (string) Str::uuid7();
                DB::table('adhesiones')->insert(['id' => $id, 'productor_id' => $productorId, 'organizacion_id' => $org->id, 'estado' => 'activa',
                    'numero_socio' => $d['numero_socio'] ?? null, 'version_vigente' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $a = DB::table('adhesiones')->where('id', $id)->first();
            }
            $this->consentir($cadena, $a, $u, 'otorga', $d['alcances'], $d['texto_version'], null);
            DB::table('adhesiones')->where('id', $a->id)->update(['estado' => 'activa', 'numero_socio' => $d['numero_socio'] ?? $a->numero_socio, 'updated_at' => now()]);
            $this->auditoria->handle($establecimientoAuditoria, $u->id, 'adhesion', $a->id, 'otorga', null, ['organizacion' => $org->slug, 'alcances' => $d['alcances']]);

            return $a->id;
        });
    }

    /** @param array<string, bool> $alcances */
    public function modificar(object $a, User $u, array $alcances, string $texto, ?string $establecimientoAuditoria): void
    {
        if ($a->estado !== 'activa') {
            throw ApiException::reglaBloqueante(__('La adhesión está revocada: volvé a adherirte.'));
        }
        DB::transaction(function () use ($a, $u, $alcances, $texto, $establecimientoAuditoria) {
            $this->consentir(Cadena::bloquearDeProductor($a->productor_id), $a, $u, 'modifica', $alcances, $texto, null);
            $this->auditoria->handle($establecimientoAuditoria, $u->id, 'adhesion', $a->id, 'modifica', null, ['alcances' => $alcances]);
        });
    }

    public function revocar(object $a, User $u, string $motivo, ?string $establecimientoAuditoria): void
    {
        if ($a->estado !== 'activa') {
            return;
        }
        DB::transaction(function () use ($a, $u, $motivo, $establecimientoAuditoria) {
            $texto = DB::table('consentimientos')->where('adhesion_id', $a->id)->orderByDesc('version')->value('texto_version');
            $this->consentir(Cadena::bloquearDeProductor($a->productor_id), $a, $u, 'revoca', array_fill_keys(self::ALCANCES, false), (string) $texto, $motivo);
            DB::table('adhesiones')->where('id', $a->id)->update(['estado' => 'revocada', 'updated_at' => now()]);
            $this->auditoria->handle($establecimientoAuditoria, $u->id, 'adhesion', $a->id, 'revoca', null, null, $motivo);
        });
    }

    /** @param array<string, bool> $alcances */
    private function consentir(Cadena $cadena, object $a, User $u, string $accion, array $alcances, string $texto, ?string $motivo): void
    {
        $alcances = array_map(fn ($k) => (bool) ($alcances[$k] ?? false), array_combine(self::ALCANCES, self::ALCANCES));
        $actual = DB::table('adhesiones')->where('id', $a->id)->first();
        $version = (int) $actual->version_vigente + 1;
        $anterior = $version > 1 ? DB::table('consentimientos')->where('adhesion_id', $a->id)->where('version', $version - 1)->value('hash') : null;
        $r = Sobres::registrar($cadena, 'tropilla.consentimiento/1', 'consentimiento', [
            'id' => (string) Str::uuid7(), 'adhesion_id' => $a->id, 'organizacion_id' => $a->organizacion_id, 'productor_id' => $a->productor_id,
            'version' => $version, 'hash_anterior' => $anterior, 'accion' => $accion, 'alcances' => $alcances, 'texto_version' => $texto, 'motivo' => $motivo,
            'otorgado_por' => $u->id,
        ]);
        DB::table('consentimientos')->insert(['id' => $r['sobre']->id, 'adhesion_id' => $a->id, 'version' => $version, 'accion' => $accion, 'alcances' => json_encode($alcances),
            'texto_version' => $texto, 'motivo' => $motivo, 'otorgado_por' => $u->id, 'sobre' => Sobres::json($r['sobre']), 'hash' => $r['huella'],
            'registro_integridad_id' => $r['registro_id'], 'created_at' => now()]);
        DB::table('adhesiones')->where('id', $a->id)->update(['version_vigente' => $version, 'updated_at' => now()]);
    }
}
