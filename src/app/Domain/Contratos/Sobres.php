<?php

namespace App\Domain\Contratos;

use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use stdClass;

/** Sobres generados por el servidor para contratos (sync §3.3): canonicalizados, hasheados y encadenados en el establecimiento. */
final class Sobres
{
    /**
     * @param  array<string, mixed>  $campos
     * @return array{sobre: stdClass, huella: string, registro_id: int}
     */
    public static function registrar(Cadena $cadena, string $esquema, string $sujeto, array $campos): array
    {
        $s = json_decode(json_encode(array_filter(['esquema' => $esquema, ...$campos, 'creado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')],
            fn ($v) => $v !== null)), false);
        $huella = CanonicalJson::huella($s);
        $r = app(RegistrarEnCadena::class)->handle($cadena, $sujeto, $s->id, $huella);

        return ['sobre' => $s, 'huella' => $huella, 'registro_id' => $r->id];
    }

    public static function json(stdClass $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
