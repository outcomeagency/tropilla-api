<?php

namespace App\Domain\Hacienda\Identificacion;

use App\Domain\Catalogos\Parametros;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use Illuminate\Support\Facades\DB;

/**
 * Concilia las filas leídas de un lector/archivo de caravanas contra la hacienda del establecimiento (TPA-63).
 * Ninguna fila se descarta: cada una sale con un estado y sus mensajes. Es la misma regla que aplica el teléfono
 * sin señal (`lib/features/identificacion_importacion/conciliador.dart`); acá se reverifica al recibir la importación.
 */
final class ConciliadorIdentificaciones
{
    /** Se pueden registrar (colocación o reposición). */
    public const IMPORTABLES = ['lista', 'reposicion'];

    public const ESTADOS = ['lista', 'reposicion', 'sin_cambios', 'registrada', 'duplicada', 'invalida', 'en_uso', 'sin_conciliar', 'otra_especie', 'omitida'];

    private const NOMBRE_ESPECIE = ['BOV' => 'bovino', 'BUB' => 'bubalino'];

    /** Solo dígitos: el bastón manda «032 000123456789» o con salto de línea. */
    public static function rfid(?string $valor): string
    {
        return (string) preg_replace('/\D+/', '', (string) $valor);
    }

    /**
     * @param  list<array<string, mixed>>  $filas  numero, rfid, visual, animal_id, evento_id
     * @return list<array<string, mixed>> cada fila + `estado_servidor`, `mensajes` y `animal_id` resuelto
     */
    public function conciliar(string $establecimientoId, string $especie, array $filas): array
    {
        $prefijo = (string) (Parametros::valor('rfid_prefijo_pais', $especie, null, $establecimientoId) ?? '');
        $rfids = array_values(array_unique(array_filter(array_map(fn ($f) => self::rfid($f['rfid'] ?? null), $filas))));
        $duenos = DB::table('animal_identificaciones')->where('establecimiento_id', $establecimientoId)->where('tipo', 'rfid')
            ->where('vigente', true)->whereIn('valor_normalizado', $rfids)->pluck('animal_id', 'valor_normalizado');
        $eventos = DB::table('eventos')->where('establecimiento_id', $establecimientoId)
            ->whereIn('id', array_values(array_filter(array_column($filas, 'evento_id'))))->pluck('id')->flip();

        $vistos = [];
        $out = [];
        foreach ($filas as $f) {
            $rfid = self::rfid($f['rfid'] ?? null);
            $msj = [];
            [$estado, $animalId] = $this->evaluar($establecimientoId, $especie, $f, $rfid, $vistos, $duenos->all(), isset($eventos[$f['evento_id'] ?? '']), $msj);
            if ($rfid !== '' && ! isset($vistos[$rfid])) {
                $vistos[$rfid] = (int) $f['numero'];
            }
            if (preg_match('/^\d{15}$/', $rfid) && $prefijo !== '' && ! str_starts_with($rfid, $prefijo)) {
                $msj[] = ['codigo' => 'rfid_prefijo', 'nivel' => 'advertencia',
                    'mensaje' => __('No empieza con :p (código de país de Argentina): revisá que sea un dispositivo oficial.', ['p' => $prefijo])];
            }
            $out[] = [...$f, 'rfid' => $rfid === '' ? ($f['rfid'] ?? null) : $rfid, 'animal_id' => $animalId, 'estado_servidor' => $estado, 'mensajes' => $msj];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $vistos
     * @param  array<string, string>  $duenos
     * @param  list<array<string, string>>  $msj
     * @return array{0: string, 1: ?string}
     */
    private function evaluar(string $est, string $especie, array $f, string $rfid, array $vistos, array $duenos, bool $eventoRegistrado, array &$msj): array
    {
        $error = function (string $codigo, string $mensaje) use (&$msj) {
            $msj[] = ['codigo' => $codigo, 'nivel' => 'error', 'mensaje' => $mensaje];
        };
        $visual = trim((string) ($f['visual'] ?? ''));
        $animalId = $f['animal_id'] ?? null;

        if ($rfid === '') {
            $error('sin_rfid', $visual === '' ? __('Fila vacía.') : __('Falta el número electrónico (RFID).'));

            return ['invalida', $animalId];
        }
        if (! preg_match('/^\d{15}$/', $rfid)) {
            $error('rfid_formato', __('El RFID tiene 15 dígitos (tiene :n).', ['n' => strlen($rfid)]));

            return ['invalida', $animalId];
        }
        if (isset($vistos[$rfid])) {
            $error('duplicada', __('Repetido: ya está en la fila :n.', ['n' => $vistos[$rfid]]));

            return ['duplicada', $animalId];
        }
        if ($eventoRegistrado) {
            return ['registrada', $animalId ?? ($duenos[$rfid] ?? null)];
        }

        $animal = null;
        if ($animalId !== null) {
            $animal = DB::table('animales_estado')->where('animal_id', $animalId)->where('establecimiento_id', $est)->first();
            if ($animal === null) {
                $error('animal_desconocido', __('El animal elegido no está en este establecimiento.'));

                return ['sin_conciliar', null];
            }
        } elseif ($visual !== '') {
            $ids = DB::table('animal_identificaciones as i')->join('animales_estado as a', 'a.animal_id', '=', 'i.animal_id')
                ->where('i.establecimiento_id', $est)->whereIn('i.tipo', ['caravana_visual', 'caravana_oficial', 'tatuaje'])->where('i.vigente', true)
                ->where('a.estado', 'activo')
                ->whereRaw(Identificaciones::SQL_SIN_CEROS.' = ?', [Identificaciones::sinCeros(Identificaciones::normalizar($visual))])
                ->distinct()->limit(3)->pluck('i.animal_id')->all();
            if ($ids === []) {
                $error('sin_animal', __('No hay un animal activo con la caravana :v.', ['v' => $visual]));

                return ['sin_conciliar', null];
            }
            if (count($ids) > 1) {
                $error('ambigua', __('La caravana :v está en más de un animal: elegí cuál.', ['v' => $visual]));

                return ['sin_conciliar', null];
            }
            $animal = DB::table('animales_estado')->where('animal_id', $ids[0])->first();
        } elseif (isset($duenos[$rfid])) {
            $animal = DB::table('animales_estado')->where('animal_id', $duenos[$rfid])->first();
        } else {
            $error('sin_animal', __('Sin caravana visual: elegí el animal.'));

            return ['sin_conciliar', null];
        }

        if ($animal->estado !== 'activo') {
            $error('animal_baja', __('El animal está dado de baja (:e).', ['e' => $animal->estado]));

            return ['sin_conciliar', $animal->animal_id];
        }
        if ($animal->especie_codigo !== $especie) {
            $error('otra_especie', __('Es :a; esta importación es de :b.', [
                'a' => self::NOMBRE_ESPECIE[$animal->especie_codigo] ?? $animal->especie_codigo, 'b' => self::NOMBRE_ESPECIE[$especie] ?? $especie,
            ]));

            return ['otra_especie', $animal->animal_id];
        }
        $dueno = $duenos[$rfid] ?? null;
        if ($dueno === $animal->animal_id) {
            $msj[] = ['codigo' => 'sin_cambios', 'nivel' => 'info', 'mensaje' => __('Ese animal ya tiene este RFID.')];

            return ['sin_cambios', $animal->animal_id];
        }
        if ($dueno !== null) {
            $otro = DB::table('animales_estado')->where('animal_id', $dueno)->value('identificacion_principal');
            $error('en_uso', __('El RFID ya está vigente en otro animal (:o).', ['o' => $otro ?? __('sin caravana')]));

            return ['en_uso', $animal->animal_id];
        }
        $anterior = DB::table('animal_identificaciones')->where('animal_id', $animal->animal_id)->where('tipo', 'rfid')->where('vigente', true)->value('valor');
        if ($anterior !== null) {
            $msj[] = ['codigo' => 'reposicion', 'nivel' => 'info', 'mensaje' => __('Reemplaza el RFID :v (queda en el historial).', ['v' => $anterior])];

            return ['reposicion', $animal->animal_id];
        }

        return ['lista', $animal->animal_id];
    }
}
