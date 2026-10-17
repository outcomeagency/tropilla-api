<?php

namespace App\Domain\Documentos\Borradores\Actions;

use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Documentos\Borradores\Borradores;
use App\Domain\Documentos\Borradores\Familias\FamiliaConRegistroPropio;
use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Confirmación **explícita** (TPA-61/62/64/65/66): única forma de que un borrador toque datos del establecimiento.
 * Exige todos los campos revisados, que no haya advertencias bloqueantes de la familia y, si hay advertencias,
 * `confirmar_advertencias`. Registra los eventos que propone la familia por el camino de siempre (`ArmarSobreWeb` +
 * `RegistrarEvento`: permisos, hash, cadena, proyecciones) con las páginas originales como adjuntos, y versiona el
 * documento (`correccion_metadatos`) vinculándolo a esos eventos y a los existentes que indique la familia (p. ej. el
 * movimiento ya cargado al que pertenece un DT-e). Todo o nada.
 */
final class ConfirmarBorrador
{
    public function __construct(
        private readonly RegistrarEvento $registrarEvento,
        private readonly RegistrarDocumentoVersion $registrarDocumento,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param 'web'|'telegram' $origen de los eventos (TPA-77: el bot confirma con origen `telegram`) */
    public function handle(Establecimiento $est, User $usuario, object $b, bool $confirmarAdvertencias, string $origen = 'web'): object
    {
        if ($b->estado === 'confirmado') {
            return $b; // idempotente
        }
        if ($b->estado !== 'para_revisar') {
            throw new ApiException(409, 'estado_invalido', __('El borrador no está para revisar (:e).', ['e' => $b->estado]));
        }
        $campos = json_decode($b->campos, true);
        $pendientes = array_values(array_filter($campos, fn ($c) => $c['estado'] === 'pendiente'));
        if ($pendientes !== []) {
            throw new ApiException(422, 'revision_incompleta', __('Faltan revisar :n campos.', ['n' => count($pendientes)]),
                array_fill_keys(array_map(fn ($c) => 'campos.'.$c['ruta'], $pendientes), [__('Sin revisar.')]));
        }
        $familia = Familias::get($b->familia);
        // Las advertencias se recalculan: lotes, caravanas y movimientos pueden haber cambiado desde la revisión.
        $salida = json_decode((string) DB::table('borrador_extracciones')->where('borrador_id', $b->id)->where('version', $b->extraccion_vigente)->value('salida'), true) ?? [];
        $advertencias = $familia->advertencias($est, $campos, $salida);
        $bloqueantes = array_values(array_filter($advertencias, fn ($a) => in_array($a['codigo'], $familia->bloqueantes(), true)));
        if ($bloqueantes !== []) {
            throw ApiException::reglaBloqueante($bloqueantes[0]['mensaje'], ['advertencias' => array_column($bloqueantes, 'codigo')]);
        }
        if ($advertencias !== [] && ! $confirmarAdvertencias) {
            throw new ApiException(422, 'requiere_confirmacion', __('Hay advertencias: confirmá para registrar igual.'), [], array_map(
                fn ($a) => ['codigo' => $a['codigo'], 'mensaje' => $a['mensaje'], 'detalle' => (object) ($a['detalle'] ?? [])], $advertencias));
        }
        $paginas = Borradores::paginas($b);

        return DB::transaction(function () use ($est, $usuario, $b, $familia, $campos, $paginas, $confirmarAdvertencias, $origen) {
            $b = DB::table('borradores_documento')->where('id', $b->id)->lockForUpdate()->first();
            if ($b->estado === 'confirmado') {
                return $b;
            }
            // TPA-66: familias que registran su propia entidad (protocolo sanitario) por la Action de su dominio.
            if ($familia instanceof FamiliaConRegistroPropio) {
                $eventoIds = $familia->registrar($est, $usuario, $b, $campos, $confirmarAdvertencias);
                $this->vincularDocumento($est, $usuario, $b, $familia->metadatosDocumento($campos), $eventoIds);
                DB::table('borradores_documento')->where('id', $b->id)->update([
                    'estado' => 'confirmado', 'evento_ids' => json_encode($eventoIds), 'confirmado_por' => $usuario->id, 'confirmado_en' => now(),
                    'row_version' => $b->row_version + 1, 'updated_at' => now(),
                ]);
                $this->auditoria->handle($est->id, $usuario->id, 'borrador_documento', $b->id, 'confirmacion', null,
                    ['eventos' => $eventoIds, 'extraccion' => (int) $b->extraccion_vigente, 'esquema' => $b->esquema]);

                return DB::table('borradores_documento')->where('id', $b->id)->first();
            }
            $plan = $familia->confirmacion($est, $campos, $b->documento_id);
            // Solo vincular (sin eventos nuevos) exige poder versionar el documento; si hay eventos, el vínculo extra es oportunista.
            if ($plan['eventos'] === [] && $plan['vincular'] !== [] && ! $this->puedeVersionar($est, $usuario, $b)) {
                throw ApiException::sinPermiso();
            }
            $eventoIds = [];
            foreach ($plan['eventos'] as $p) {
                foreach ($p['sujetos'] as $s) {
                    $in = json_decode(json_encode(['id' => (string) Str::uuid7(), 'tipo' => $p['tipo'], 'sujetos' => $s, 'payload' => (object) $p['payload'],
                        'ocurrido_en' => $p['ocurrido_en'], 'observaciones' => $p['observaciones'], 'adjuntos' => $paginas]), false);
                    $r = $this->registrarEvento->handle($est, $usuario, ArmarSobreWeb::alta($est, $usuario, $in, $origen), ['canal' => 'web', 'confirmar_advertencias' => $confirmarAdvertencias]);
                    $eventoIds[] = $r->evento->id;
                }
            }
            $vinculados = array_values(array_unique($plan['vincular']));
            $this->vincularDocumento($est, $usuario, $b, $familia->metadatosDocumento($campos), [...$eventoIds, ...$vinculados]);

            DB::table('borradores_documento')->where('id', $b->id)->update([
                'estado' => 'confirmado', 'evento_ids' => json_encode($eventoIds), 'eventos_vinculados' => json_encode($vinculados),
                'confirmado_por' => $usuario->id, 'confirmado_en' => now(), 'row_version' => $b->row_version + 1, 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $usuario->id, 'borrador_documento', $b->id, 'confirmacion', null,
                ['eventos' => $eventoIds, 'vinculados' => $vinculados, 'extraccion' => (int) $b->extraccion_vigente, 'esquema' => $b->esquema]);

            return DB::table('borradores_documento')->where('id', $b->id)->first();
        });
    }

    private function puedeVersionar(Establecimiento $est, User $usuario, object $b): bool
    {
        $doc = DB::table('documentos')->where('id', $b->documento_id)->first();
        $g = Gate::forUser($usuario);

        return $doc->estado === 'vigente' && $g->allows('documentos.versionar', $est) && ($g->allows('documentos.fiscal', $est) || $doc->creado_por === $usuario->id);
    }

    /**
     * Nueva versión del documento con los vínculos a los eventos (y fecha/emisor si estaban vacíos). Si el rol no puede
     * versionarlo (encargado/asesor sobre un documento ajeno) se omite: los eventos ya llevan las páginas como adjuntos.
     *
     * @param  array{emitido_en?: string, emisor?: array<string, string>}  $meta
     * @param  list<string>  $eventoIds
     */
    private function vincularDocumento(Establecimiento $est, User $usuario, object $b, array $meta, array $eventoIds): void
    {
        $doc = DB::table('documentos')->where('id', $b->documento_id)->lockForUpdate()->first();
        if (! $this->puedeVersionar($est, $usuario, $b)) {
            return;
        }
        $vigente = DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $doc->version_vigente)->where('en_revision', false)->first();
        $base = json_decode($vigente->sobre, true);
        $vinculos = $base['vinculos'] ?? [];
        $nuevos = array_diff($eventoIds, array_column(array_filter($vinculos, fn ($v) => ($v['tipo'] ?? null) === 'evento'), 'id'));
        foreach ($nuevos as $id) {
            $vinculos[] = ['tipo' => 'evento', 'id' => $id];
        }
        $in = ['accion' => 'correccion_metadatos', 'motivo' => 'Datos revisados desde la lectura asistida del documento.', 'vinculos' => $vinculos];
        if (empty($base['emitido_en']) && isset($meta['emitido_en']) && (empty($base['vence_en']) || $base['vence_en'] >= $meta['emitido_en'])) {
            $in['emitido_en'] = $meta['emitido_en'];
        }
        if (empty($base['emisor']) && isset($meta['emisor'])) {
            $in['emisor'] = $meta['emisor'];
        }
        if ($nuevos === [] && count($in) === 3) {
            return; // nada que cambiar
        }
        $this->registrarDocumento->handle($est, $usuario, ArmarSobreDocumentoWeb::nuevaVersion($doc, $usuario, $in), ['canal' => 'web']);
    }
}
