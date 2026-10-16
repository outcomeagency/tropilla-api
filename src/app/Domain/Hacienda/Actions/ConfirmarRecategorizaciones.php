<?php

namespace App\Domain\Hacienda\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Hacienda\Models\Evento;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Confirmación en bloque (TPA-25, modelo-datos §6.4): **un evento `hacienda.recategorizacion` por animal**. Si la
 * categoría confirmada es la sugerida, el evento lleva `sugerida: true`, el `regla_id` y el motivo de la regla;
 * si el usuario eligió otra, `motivo: manual`. Todo o nada (una transacción).
 */
final class ConfirmarRecategorizaciones
{
    public function __construct(private readonly RegistrarEvento $registrar) {}

    /**
     * @param  list<array{animal_id: string, categoria: string}>  $items
     * @return list<Evento>
     */
    public function handle(Establecimiento $est, User $autor, array $items, ?string $ocurridoEn): array
    {
        $fecha = $ocurridoEn ?? now()->setTimezone($est->zona_horaria)->toDateString();

        return DB::transaction(function () use ($est, $autor, $items, $fecha) {
            $eventos = [];
            foreach ($items as $item) {
                $estado = DB::table('animales_estado')->where('animal_id', $item['animal_id'])->where('establecimiento_id', $est->id)->first();
                $sugerida = $estado && $estado->categoria_sugerida_codigo === $item['categoria'];
                $regla = $sugerida ? DB::table('reglas_categoria')->where('id', $estado->categoria_sugerida_regla_id)->first() : null;
                $payload = array_filter([
                    'categoria_nueva' => $item['categoria'],
                    'motivo' => $regla->motivo ?? 'manual',
                    'sugerida' => $sugerida,
                    'regla_id' => $regla ? (int) $regla->id : null,
                ], fn ($v) => $v !== null);
                $in = json_decode(json_encode(['tipo' => 'hacienda.recategorizacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$item['animal_id']]],
                    'payload' => $payload, 'ocurrido_en' => $fecha]), false);
                $eventos[] = $this->registrar->handle($est, $autor, ArmarSobreWeb::alta($est, $autor, $in), ['canal' => 'web', 'confirmar_advertencias' => true])->evento;
            }

            return $eventos;
        });
    }
}
