<?php

namespace App\Domain\Integridad\Anclaje;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * OpenTimestamps (staging/producción): envía el digest (la raíz, 32 bytes) a ≥ 2 calendarios públicos y arma el `.ots`
 * con una rama por calendario. `actualizar` pide a cada calendario la versión de su compromiso pendiente; cuando aparece
 * una atestación de Bitcoin → confirmado con la altura de bloque (y la hora del bloque si el explorador responde).
 * Sin wallet ni claves; cualquiera verifica con el cliente `ots` y la raíz.
 */
final class OpenTimestampsDriver implements AnchorDriver
{
    /** @param list<string> $calendarios */
    public function __construct(private readonly array $calendarios, private readonly ?string $explorador = null) {}

    public function nombre(): string
    {
        return 'opentimestamps';
    }

    public function sellar(string $raizHex): ReciboSello
    {
        $digest = (string) hex2bin($raizHex);
        $nodo = ['msg' => $digest, 'atestaciones' => [], 'ops' => []];
        $ok = [];
        foreach ($this->calendarios as $cal) {
            try {
                $r = Http::timeout(15)->withHeaders(['Accept' => 'application/vnd.opentimestamps.v1', 'User-Agent' => 'tropilla-api'])
                    ->withBody($digest, 'application/octet-stream')->post(rtrim($cal, '/').'/digest');
                if (! $r->successful()) {
                    continue;
                }
                $nodo = Ots::injertar($nodo, [], Ots::leerTimestamp($r->body(), $digest));
                $ok[] = $cal;
            } catch (Throwable) {
                continue;
            }
        }
        if (count($ok) < min(2, count($this->calendarios))) {
            throw new RuntimeException('Menos de 2 calendarios OpenTimestamps respondieron.');
        }

        return new ReciboSello(Ots::archivo($digest, $nodo), 'bitcoin', ['calendarios' => $ok]);
    }

    public function actualizar(string $raizHex, string $recibo): EstadoAnclaje
    {
        ['digest' => $digest, 'nodo' => $nodo] = Ots::leerArchivo($recibo);
        $cambio = false;
        if (Ots::alturaBitcoin($nodo) === null) {
            foreach (Ots::pendientes($nodo) as $p) {
                try {
                    $r = Http::timeout(15)->withHeaders(['Accept' => 'application/vnd.opentimestamps.v1', 'User-Agent' => 'tropilla-api'])
                        ->get(rtrim($p['uri'], '/').'/timestamp/'.bin2hex($p['msg']));
                    if ($r->successful()) {
                        $nodo = Ots::injertar($nodo, $p['ruta'], Ots::leerTimestamp($r->body(), $p['msg']));
                        $cambio = true;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }
        $altura = Ots::alturaBitcoin($nodo);
        if ($altura === null) {
            return new EstadoAnclaje('enviado', $cambio ? Ots::archivo($digest, $nodo) : null);
        }

        return new EstadoAnclaje('confirmado', $cambio ? Ots::archivo($digest, $nodo) : null,
            ['red' => 'bitcoin', 'bloque' => $altura, 'block_time' => $this->horaBloque($altura)]);
    }

    private function horaBloque(int $altura): ?string
    {
        if (! $this->explorador) {
            return null;
        }
        try {
            $hash = trim(Http::timeout(10)->get(rtrim($this->explorador, '/')."/block-height/{$altura}")->body());
            $t = Http::timeout(10)->get(rtrim($this->explorador, '/')."/block/{$hash}")->json('timestamp');

            return $t ? gmdate('Y-m-d\TH:i:s.000\Z', (int) $t) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
