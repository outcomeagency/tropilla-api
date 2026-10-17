<?php

namespace App\Domain\Documentos\Borradores\Actions;

use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Documentos\Borradores\Borradores;
use App\Domain\Documentos\Borradores\Campos;
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
 * Confirmación **explícita** (TPA-61/62): única forma de que un borrador toque datos del establecimiento.
 * Exige todos los campos revisados y, si hay advertencias, `confirmar_advertencias`. Registra los eventos que propone la
 * familia por el camino de siempre (`ArmarSobreWeb` + `RegistrarEvento`: hash, cadena, proyecciones) con las páginas
 * originales como adjuntos, y versiona el documento (`correccion_metadatos`) vinculándolo a esos eventos. Todo o nada.
 * Sujetos: animales activos de las categorías vacunadas (en tandas de 1000) y lotes anónimos con cabezas de esas categorías.
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
        $advertencias = json_decode($b->advertencias, true);
        if (in_array('categoria_otra_especie', array_column($advertencias, 'codigo'), true)) {
            throw ApiException::reglaBloqueante(__('Hay categorías de otra especie: corregilas antes de confirmar.'));
        }
        if ($advertencias !== [] && ! $confirmarAdvertencias) {
            throw new ApiException(422, 'requiere_confirmacion', __('Hay advertencias: confirmá para registrar igual.'), [], array_map(
                fn ($a) => ['codigo' => $a['codigo'], 'mensaje' => $a['mensaje'], 'detalle' => (object) ($a['detalle'] ?? [])], $advertencias));
        }

        $familia = Familias::get($b->familia);
        $propuestos = $familia->eventos($campos);
        if ($propuestos === [] && Campos::valor($campos, 'alcance') !== 'cero') {
            throw ApiException::reglaBloqueante(__('No hay nada para registrar: faltan la fecha, la enfermedad o categorías con vacunados.'));
        }
        if ($propuestos !== []) {
            Gate::forUser($usuario)->authorize('sanidad.crear', $est);
        }
        $paginas = Borradores::paginas($b);

        return DB::transaction(function () use ($est, $usuario, $b, $familia, $campos, $propuestos, $paginas, $confirmarAdvertencias, $origen) {
            $b = DB::table('borradores_documento')->where('id', $b->id)->lockForUpdate()->first();
            if ($b->estado === 'confirmado') {
                return $b;
            }
            $eventoIds = [];
            foreach ($propuestos as $p) {
                $sujetos = $this->sujetos($est, $p['categorias']);
                if ($sujetos === []) {
                    throw ApiException::reglaBloqueante(__('No hay animales ni lotes de esas categorías en el establecimiento para registrar la vacunación.'),
                        ['categorias' => $p['categorias']]);
                }
                foreach ($sujetos as $s) {
                    $in = json_decode(json_encode(['id' => (string) Str::uuid7(), 'tipo' => $p['tipo'], 'sujetos' => $s, 'payload' => (object) $p['payload'],
                        'ocurrido_en' => $p['ocurrido_en'], 'observaciones' => $p['observaciones'], 'adjuntos' => $paginas]), false);
                    $r = $this->registrarEvento->handle($est, $usuario, ArmarSobreWeb::alta($est, $usuario, $in, $origen), ['canal' => 'web', 'confirmar_advertencias' => $confirmarAdvertencias]);
                    $eventoIds[] = $r->evento->id;
                }
            }
            $this->vincularDocumento($est, $usuario, $b, $familia->metadatosDocumento($campos), $eventoIds);

            DB::table('borradores_documento')->where('id', $b->id)->update([
                'estado' => 'confirmado', 'evento_ids' => json_encode($eventoIds), 'confirmado_por' => $usuario->id, 'confirmado_en' => now(),
                'row_version' => $b->row_version + 1, 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $usuario->id, 'borrador_documento', $b->id, 'confirmacion', null,
                ['eventos' => $eventoIds, 'extraccion' => (int) $b->extraccion_vigente, 'esquema' => $b->esquema]);

            return DB::table('borradores_documento')->where('id', $b->id)->first();
        });
    }

    /**
     * @param  list<string>  $categorias
     * @return list<array<string, mixed>>
     */
    private function sujetos(Establecimiento $est, array $categorias): array
    {
        $ids = DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->whereIn('categoria_codigo', $categorias)
            ->orderBy('animal_id')->pluck('animal_id')->all();
        $out = array_map(fn ($tanda) => ['tipo' => 'animales', 'animal_ids' => $tanda], array_chunk($ids, 1000));
        $lotes = DB::table('lote_conteos as lc')->join('lotes as l', 'l.id', '=', 'lc.lote_id')->where('l.establecimiento_id', $est->id)->where('l.anonimo', true)
            ->whereIn('lc.categoria_codigo', $categorias)->where('lc.cabezas', '>', 0)->distinct()->orderBy('l.id')->pluck('l.id')->all();
        foreach ($lotes as $lote) {
            $out[] = ['tipo' => 'lote', 'lote_id' => $lote];
        }

        return $out;
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
        $g = Gate::forUser($usuario);
        if ($doc->estado !== 'vigente' || ! $g->allows('documentos.versionar', $est) || (! $g->allows('documentos.fiscal', $est) && $doc->creado_por !== $usuario->id)) {
            return;
        }
        $vigente = DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $doc->version_vigente)->where('en_revision', false)->first();
        $base = json_decode($vigente->sobre, true);
        $vinculos = $base['vinculos'] ?? [];
        foreach ($eventoIds as $id) {
            $vinculos[] = ['tipo' => 'evento', 'id' => $id];
        }
        $in = ['accion' => 'correccion_metadatos', 'motivo' => 'Datos revisados desde la lectura asistida del documento.', 'vinculos' => $vinculos];
        if (empty($base['emitido_en']) && isset($meta['emitido_en']) && (empty($base['vence_en']) || $base['vence_en'] >= $meta['emitido_en'])) {
            $in['emitido_en'] = $meta['emitido_en'];
        }
        if (empty($base['emisor']) && isset($meta['emisor'])) {
            $in['emisor'] = $meta['emisor'];
        }
        if ($eventoIds === [] && count($in) === 3) {
            return; // nada que cambiar
        }
        $this->registrarDocumento->handle($est, $usuario, ArmarSobreDocumentoWeb::nuevaVersion($doc, $usuario, $in), ['canal' => 'web']);
    }
}
