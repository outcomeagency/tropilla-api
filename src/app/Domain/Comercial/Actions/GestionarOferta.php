<?php

namespace App\Domain\Comercial\Actions;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Comercial\Models\Oferta;
use App\Domain\Comercial\Services\Compromisos;
use App\Domain\Comercial\Services\ContenidoOferta;
use App\Domain\Contratos\Seguidores;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Perfil\Services\Perfiles;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ofertas (TPA-48): borrador → (avisos, vista previa) → **publicar** = versión nueva en `oferta_publicaciones`
 * con el contenido congelado (lo que ve el comprador), sobre `tropilla.oferta_publicacion/1` hasheado y
 * encadenado. Republicar = otra versión. Nada se borra: retirar/vender cambia el estado (auditado).
 * Compromisos: no se publica hacienda que ya está en otra oferta activa, en contrato o vendida (409 `comprometido`).
 */
final class GestionarOferta
{
    public function __construct(private readonly RegistrarEnCadena $cadena, private readonly RegistrarAuditoria $auditoria) {}

    /** @param array<string, mixed> $d */
    public function crear(Establecimiento $est, User $u, array $d): Oferta
    {
        return DB::transaction(function () use ($est, $u, $d) {
            [$especie, $animalIds] = $this->seleccion($est, $d['especie'] ?? null, $d['lote_id'] ?? null, $d['animal_ids'] ?? []);
            $pre = ContenidoOferta::precarga($est->id, $especie, $d['lote_id'] ?? null, $animalIds);
            if ($pre['cantidad'] === 0 && empty($d['cantidad'])) {
                throw ApiException::validacion(__('La oferta necesita animales o una cantidad.'), ['cantidad' => [__('Obligatoria.')]]);
            }
            $o = new Oferta([
                'establecimiento_id' => $est->id, 'codigo_publico' => self::codigo(), 'tipo' => $d['tipo'], 'especie_codigo' => $especie,
                'lote_id' => $d['lote_id'] ?? null, 'cantidad' => (int) ($d['cantidad'] ?? $pre['cantidad']),
                'categorias' => $d['categorias'] ?? array_column($pre['categorias'], 'codigo'), 'razas' => $d['razas'] ?? array_column($pre['razas'], 'codigo'),
                'mostrar' => $d['mostrar'] ?? array_fill_keys(ContenidoOferta::BLOQUES, true), 'condiciones' => $d['condiciones'] ?? ['modalidad' => 'en_pie', 'mostrar_precio' => false],
                'textos' => $d['textos'] ?? self::textosDefault($pre), 'fotos' => $d['fotos'] ?? null, 'creado_por' => $u->id,
            ]);
            $o->save();
            $this->guardarAnimales($o, $animalIds);
            $this->auditoria->handle($est->id, $u->id, 'oferta', $o->id, 'crear', null, ['tipo' => $o->tipo, 'cantidad' => $o->cantidad]);

            return $o->refresh();
        });
    }

    /** @param array<string, mixed> $d */
    public function editar(Oferta $o, User $u, array $d): Oferta
    {
        return DB::transaction(function () use ($o, $u, $d) {
            /** @var Oferta $o */
            $o = Oferta::query()->lockForUpdate()->findOrFail($o->id);
            if ((int) $d['row_version'] !== $o->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $o->row_version]);
            }
            if (in_array($o->estado, ['vendida', 'retirada'], true)) {
                throw ApiException::reglaBloqueante(__('Una oferta vendida o retirada no se edita.'));
            }
            $antes = $o->only(['tipo', 'cantidad', 'mostrar', 'condiciones', 'textos']);
            $o->fill(array_intersect_key($d, array_flip(['tipo', 'cantidad', 'categorias', 'razas', 'mostrar', 'condiciones', 'textos', 'fotos'])));
            if (array_key_exists('animal_ids', $d) || array_key_exists('lote_id', $d)) {
                $est = Establecimiento::query()->findOrFail($o->establecimiento_id);
                [, $ids] = $this->seleccion($est, $o->especie_codigo, $d['lote_id'] ?? $o->lote_id, $d['animal_ids'] ?? []);
                $o->lote_id = $d['lote_id'] ?? $o->lote_id;
                $this->guardarAnimales($o, $ids);
            }
            $o->row_version++;
            $o->save();
            $this->auditoria->handle($o->establecimiento_id, $u->id, 'oferta', $o->id, 'editar', $antes, $o->only(['tipo', 'cantidad', 'mostrar', 'condiciones', 'textos']));

            return $o;
        });
    }

    /** @return array{precarga: array<string, mixed>, avisos: list<array<string, string>>} */
    public function evaluar(Oferta $o): array
    {
        $pre = ContenidoOferta::precarga($o->establecimiento_id, $o->especie_codigo, $o->lote_id, $o->animalIds());

        return ['precarga' => $pre, 'avisos' => ContenidoOferta::avisos($pre, $o->establecimiento_id)];
    }

    /** @return array<string, mixed> contenido tal como lo vería el comprador (sin guardar) */
    public function contenidoPublico(Oferta $o): array
    {
        $pre = $this->evaluar($o)['precarga'];

        return ContenidoOferta::publico($pre, $o->tipo, $o->cantidad, $o->mostrar ?? [], $o->condiciones ?? [], $o->textos ?? []);
    }

    public function publicar(Oferta $o, User $u, bool $confirmarAvisos): Oferta
    {
        return DB::transaction(function () use ($o, $u, $confirmarAvisos) {
            $cad = Cadena::bloquearDeEstablecimiento($o->establecimiento_id);
            /** @var Oferta $o */
            $o = Oferta::query()->lockForUpdate()->findOrFail($o->id);
            if (in_array($o->estado, ['vendida', 'retirada'], true)) {
                throw ApiException::reglaBloqueante(__('La oferta está :e.', ['e' => $o->estado]));
            }
            $u->emailVerificado() || throw ApiException::reglaBloqueante(__('Verificá tu email antes de publicar.'));

            $this->verificarCompromisos($o);
            ['precarga' => $pre, 'avisos' => $avisos] = $this->evaluar($o);
            if ($avisos !== [] && ! $confirmarAvisos) {
                throw new ApiException(422, 'requiere_confirmacion', __('Revisá los avisos antes de publicar.'), [], array_map(fn ($a) => [...$a, 'detalle' => (object) []], $avisos));
            }
            $contenido = ContenidoOferta::publico($pre, $o->tipo, $o->cantidad, $o->mostrar ?? [], $o->condiciones ?? [], $o->textos ?? []);
            $version = ($o->publicacion_vigente ?? 0) + 1;
            $id = (string) Str::uuid7();
            $sobre = json_decode(json_encode([
                'esquema' => 'tropilla.oferta_publicacion/1', 'id' => $id, 'establecimiento_id' => $o->establecimiento_id,
                'oferta_id' => $o->id, 'codigo' => $o->codigo_publico, 'version' => $version, 'estado' => 'publicada',
                'contenido_publico' => $contenido, 'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'autor_id' => $u->id, 'origen' => 'web',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
            $huella = CanonicalJson::huella($sobre);
            $reg = $this->cadena->handle($cad, 'oferta_publicacion', $id, $huella);
            DB::table('oferta_publicaciones')->insert([
                'id' => $id, 'oferta_id' => $o->id, 'version' => $version,
                'contenido' => json_encode($sobre->contenido_publico, JSON_UNESCAPED_UNICODE), 'sobre' => json_encode($sobre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'hash' => $huella, 'publicado_por' => $u->id, 'registro_integridad_id' => $reg->id, 'created_at' => now(),
            ]);
            $o->forceFill(['estado' => 'publicada', 'publicacion_vigente' => $version, 'publicada_en' => $o->publicada_en ?? now(),
                'avisos' => array_map(fn ($a) => [...$a, 'confirmado' => true], $avisos)])->save();
            Perfiles::asegurar(Establecimiento::query()->findOrFail($o->establecimiento_id));
            $this->auditoria->handle($o->establecimiento_id, $u->id, 'oferta', $o->id, 'publicar', null, ['version' => $version, 'huella' => $huella]);
            if ($version === 1) {
                $est = Establecimiento::query()->findOrFail($o->establecimiento_id);
                Seguidores::avisar($est, 'oferta_nueva', "{$est->nombre} publicó una oferta", ($o->textos['es']['titulo'] ?? null) ?: "{$o->cantidad} cabezas",
                    ['oferta_codigo' => $o->codigo_publico, 'ruta' => '/o/'.$o->codigo_publico], "oferta:{$o->id}:publicada");
            }

            return $o;
        });
    }

    public function cambiarEstado(Oferta $o, User $u, string $estado, ?string $motivo): Oferta
    {
        return DB::transaction(function () use ($o, $u, $estado, $motivo) {
            /** @var Oferta $o */
            $o = Oferta::query()->lockForUpdate()->findOrFail($o->id);
            $validos = ['publicada' => ['reservada', 'vendida', 'retirada'], 'reservada' => ['publicada', 'vendida', 'retirada'], 'borrador' => ['retirada']];
            if (! in_array($estado, $validos[$o->estado] ?? [], true)) {
                throw ApiException::reglaBloqueante(__('No se puede pasar de :a a :b.', ['a' => $o->estado, 'b' => $estado]));
            }
            if ($estado === 'retirada' && trim((string) $motivo) === '') {
                throw ApiException::validacion(__('Retirar exige motivo.'), ['motivo' => [__('Obligatorio.')]]);
            }
            $antes = $o->estado;
            $o->forceFill(['estado' => $estado, 'motivo_retiro' => $motivo, 'retirada_en' => in_array($estado, ['retirada', 'vendida'], true) ? now() : null,
                'row_version' => $o->row_version + 1])->save();
            $this->auditoria->handle($o->establecimiento_id, $u->id, 'oferta', $o->id, 'editar', ['estado' => $antes], ['estado' => $estado], $motivo);

            return $o;
        });
    }

    /** @return array{0: string, 1: list<string>} [especie, animal_ids] validados contra el establecimiento */
    private function seleccion(Establecimiento $est, ?string $especie, ?string $loteId, array $animalIds): array
    {
        if ($loteId) {
            $lote = DB::table('lotes')->where('id', $loteId)->where('establecimiento_id', $est->id)->first()
                ?? throw ApiException::validacion(__('El lote no existe.'), ['lote_id' => [__('No existe.')]]);
            $especie ??= $lote->especie_codigo;
            if ($lote->especie_codigo !== $especie) {
                throw ApiException::reglaBloqueante(__('El lote es de otra especie.'));
            }
        }
        $animalIds = array_values(array_unique($animalIds));
        if ($animalIds !== []) {
            $filas = DB::table('animales_estado')->where('establecimiento_id', $est->id)->whereIn('animal_id', $animalIds)->get(['animal_id', 'especie_codigo', 'estado']);
            if ($filas->count() !== count($animalIds)) {
                throw ApiException::validacion(__('Hay animales que no son del establecimiento.'), ['animal_ids' => [__('Animales desconocidos.')]]);
            }
            $especies = $filas->pluck('especie_codigo')->unique();
            if ($especies->count() > 1 || ($especie && $especies->first() !== $especie)) {
                throw ApiException::reglaBloqueante(__('Una oferta no mezcla especies.'));
            }
            $especie ??= $especies->first();
        }
        if (! $especie) {
            throw ApiException::validacion(__('Falta la especie.'), ['especie' => [__('Obligatoria sin lote ni animales.')]]);
        }
        // Lote con animales individuales: la oferta congela esos animales (así quedan comprometidos uno por uno).
        if ($animalIds === [] && $loteId && ! DB::table('lotes')->where('id', $loteId)->value('anonimo')) {
            $animalIds = DB::table('animales_estado')->where('lote_id', $loteId)->where('estado', 'activo')->orderBy('animal_id')->pluck('animal_id')->all();
        }

        return [$especie, $animalIds];
    }

    /** @param list<string> $ids */
    private function guardarAnimales(Oferta $o, array $ids): void
    {
        if ($o->estado !== 'borrador' && $ids !== $o->animalIds()) {
            // publicada: cambiar la selección exige republicar (queda en la próxima publicación)
        }
        DB::table('oferta_animales')->where('oferta_id', $o->id)->delete();
        foreach ($ids as $id) {
            DB::table('oferta_animales')->insert(['oferta_id' => $o->id, 'animal_id' => $id, 'establecimiento_id' => $o->establecimiento_id, 'incluido_en' => now()]);
        }
    }

    private function verificarCompromisos(Oferta $o): void
    {
        $ids = $o->animalIds();
        if ($ids === [] && $o->lote_id) {
            $ids = DB::table('animales_estado')->where('lote_id', $o->lote_id)->where('estado', 'activo')->pluck('animal_id')->all();
        }
        $comprometidos = Compromisos::deAnimales($ids, $o->id);
        if ($comprometidos !== []) {
            throw new ApiException(409, 'comprometido', __('Hay animales que ya están en otra oferta, en un contrato o vendidos.'), ['animal_ids' => array_map(fn ($id, $e) => "{$id}: {$e}", array_keys($comprometidos), $comprometidos)]);
        }
        if ($o->lote_id && $o->animalIds() === []) {
            $disponibles = (int) DB::table('lote_conteos')->where('lote_id', $o->lote_id)->sum('cabezas')
                + (int) DB::table('animales_estado')->where('lote_id', $o->lote_id)->where('estado', 'activo')->count();
            if ($o->cantidad + Compromisos::cabezasComprometidasDeLote($o->lote_id, $o->id) > $disponibles) {
                throw new ApiException(409, 'comprometido', __('El lote no tiene tantas cabezas libres.'), ['cantidad' => [__('Disponibles: :n.', ['n' => $disponibles - Compromisos::cabezasComprometidasDeLote($o->lote_id, $o->id)])]]);
            }
        }
    }

    /** 10 caracteres Crockford base32 (no adivinable, estable). */
    public static function codigo(): string
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        do {
            $c = '';
            foreach (str_split(random_bytes(10)) as $b) {
                $c .= $alfabeto[ord($b) % 32];
            }
        } while (DB::table('ofertas')->where('codigo_publico', $c)->exists());

        return $c;
    }

    /** @param array<string, mixed> $pre */
    private static function textosDefault(array $pre): array
    {
        $cat = $pre['categorias'][0]['codigo'] ?? null;
        $nombre = fn (string $l) => $cat ? (string) Catalogos::nombre(DB::table('categorias')->where('codigo', $cat)->value('nombres'), $l) : '';
        $raza = $pre['razas'][0]['codigo'] ?? null;
        $razaN = $raza ? ' '.Catalogos::nombre(DB::table('razas')->where('codigo', $raza)->value('nombres'), 'es') : '';

        return [
            'es' => ['titulo' => trim("{$pre['cantidad']} {$nombre('es')}{$razaN}"), 'descripcion' => ''],
            'en' => ['titulo' => trim("{$pre['cantidad']} {$nombre('en')}{$razaN}"), 'descripcion' => ''],
            'pt' => ['titulo' => trim("{$pre['cantidad']} {$nombre('pt')}{$razaN}"), 'descripcion' => ''],
        ];
    }
}
