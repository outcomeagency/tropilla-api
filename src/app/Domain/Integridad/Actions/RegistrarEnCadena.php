<?php

namespace App\Domain\Integridad\Actions;

use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Agrega un eslabón a la cadena (sync-e-integridad.md §5.3). La cadena tiene que venir **bloqueada**
 * (`Cadena::bloquearDeEstablecimiento()` = SELECT … FOR UPDATE) dentro de la transacción que inserta
 * el registro de evidencia: así el orden de la cadena es el orden de llegada y no hay dos `seq` iguales.
 *
 *   eslabon = SHA-256(eslabon_anterior + "\n" + huella)   (génesis: 64 ceros)
 */
final class RegistrarEnCadena
{
    /** @return object{id: int, seq: int, eslabon: string, eslabon_anterior: string, registrado_en: Carbon} */
    public function handle(Cadena $cadena, string $sujetoTipo, string $sujetoId, string $huella): object
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RegistrarEnCadena se usa dentro de la transacción que bloquea la cadena.');
        }

        $seq = $cadena->ultimo_seq + 1;
        $anterior = $cadena->ultimo_eslabon;
        $eslabon = CanonicalJson::eslabon($anterior, $huella);
        $ahora = now();

        $id = DB::table('registros_integridad')->insertGetId([
            'cadena_id' => $cadena->id,
            'seq' => $seq,
            'sujeto_tipo' => $sujetoTipo,
            'sujeto_id' => $sujetoId,
            'huella' => $huella,
            'eslabon_anterior' => $anterior,
            'eslabon' => $eslabon,
            'registrado_en' => $ahora,
        ]);

        Cadena::query()->whereKey($cadena->id)->update(['ultimo_seq' => $seq, 'ultimo_eslabon' => $eslabon]);
        $cadena->ultimo_seq = $seq;
        $cadena->ultimo_eslabon = $eslabon;

        return (object) ['id' => (int) $id, 'seq' => $seq, 'eslabon' => $eslabon, 'eslabon_anterior' => $anterior, 'registrado_en' => $ahora];
    }
}
