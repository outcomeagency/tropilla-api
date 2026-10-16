<?php

namespace App\Console\Commands;

use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\FuentesSobre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job nocturno (sync-e-integridad.md §5.4): recorre cada cadena por `seq`, recalcula la huella desde el
 * sobre guardado y el eslabón desde el anterior. Cualquier diferencia → alerta crítica (nunca se «arregla» la fila).
 */
class VerificarCadenas extends Command
{
    protected $signature = 'integridad:verificar-cadenas {--cadena= : Verificar solo esta cadena}';

    protected $description = 'Recalcula huellas y eslabones de las cadenas de integridad y alerta si algo no coincide';

    public function handle(): int
    {
        $fallas = 0;
        $cadenas = DB::table('cadenas')->when($this->option('cadena'), fn ($q, $id) => $q->where('id', $id))->get();

        foreach ($cadenas as $cadena) {
            $anterior = str_repeat('0', 64);
            $esperado = 1;
            $ultimo = null;
            foreach (DB::table('registros_integridad')->where('cadena_id', $cadena->id)->orderBy('seq')->cursor() as $r) {
                $problemas = [];
                if ((int) $r->seq !== $esperado) {
                    $problemas[] = "hueco de seq (esperado {$esperado})";
                }
                if ($r->eslabon_anterior !== $anterior) {
                    $problemas[] = 'eslabon_anterior no coincide';
                }
                if (CanonicalJson::eslabon($r->eslabon_anterior, $r->huella) !== $r->eslabon) {
                    $problemas[] = 'eslabon recalculado distinto';
                }
                $sobre = FuentesSobre::sobre($r->sujeto_tipo, $r->sujeto_id);
                if ($sobre !== null) {
                    try {
                        if (CanonicalJson::huella(CanonicalJson::decode($sobre)) !== $r->huella) {
                            $problemas[] = 'huella recalculada del sobre distinta';
                        }
                    } catch (CanonicalizacionInvalida $e) {
                        $problemas[] = 'sobre no canonicalizable: '.$e->getMessage();
                    }
                }
                if ($problemas !== []) {
                    $fallas++;
                    Log::critical('TROPILLA integridad: registro alterado', ['cadena_id' => $cadena->id, 'seq' => $r->seq, 'problemas' => $problemas]);
                    $this->error("cadena {$cadena->id} seq {$r->seq}: ".implode('; ', $problemas));
                }
                $anterior = $r->eslabon;
                $esperado = (int) $r->seq + 1;
                $ultimo = $r;
            }
            if ($ultimo !== null && ($ultimo->eslabon !== $cadena->ultimo_eslabon || (int) $ultimo->seq !== (int) $cadena->ultimo_seq)) {
                $fallas++;
                $this->error("cadena {$cadena->id}: la cabeza no coincide con el último registro");
            }
        }

        if ($fallas > 0) {
            return self::FAILURE;
        }
        $this->info('Cadenas verificadas: '.$cadenas->count().'. Sin diferencias.');

        return self::SUCCESS;
    }
}
