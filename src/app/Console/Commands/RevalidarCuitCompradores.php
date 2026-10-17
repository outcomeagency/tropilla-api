<?php

namespace App\Console\Commands;

use App\Domain\Contrapartes\Fuentes\BcraCentralDeudores;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Revalida en lotes chicos los perfiles compradores con consentimiento previo (TPA-79). */
final class RevalidarCuitCompradores extends Command
{
    protected $signature = 'compradores:revalidar-cuit {--limite=100}';

    protected $description = 'Revalida CUIT de compradores con consentimiento previo usando BCRA';

    public function handle(BcraCentralDeudores $bcra, RegistrarAuditoria $auditoria): int
    {
        $filas = DB::table('compradores')->where('consentimiento_cuit', true)
            ->where(fn ($q) => $q->whereNull('validacion_cuit_en')->orWhere('validacion_cuit_en', '<', now()->subDays(30)))
            ->orderBy('validacion_cuit_en')->limit(max(1, min(500, (int) $this->option('limite'))))->get();
        $actualizadas = 0;
        foreach ($filas as $fila) {
            try {
                $cuit = Crypt::decryptString($fila->cuit_cifrado);
            } catch (\Throwable) {
                continue;
            }
            $fuente = $bcra->consultar($cuit);
            if ($fuente['estado'] !== 'ok') {
                $auditoria->handle(null, null, 'validacion_cuit_comprador', (string) $fila->usuario_id, 'revalidacion', null,
                    ['cuit_hash' => $fila->cuit_hash, 'fuente' => 'bcra_central_deudores', 'estado_fuente' => $fuente['estado']]);

                continue;
            }
            $informada = $fuente['datos']['denominacion'] ?? null;
            $estado = ! $informada ? 'pendiente' : (mb_strtolower(trim($informada)) === mb_strtolower(trim((string) $fila->razon_social_declarada)) ? 'validado' : 'inconsistente');
            DB::table('compradores')->where('usuario_id', $fila->usuario_id)->update(['razon_social_informada' => $informada, 'validacion_cuit_estado' => $estado,
                'validacion_cuit_fuente' => 'bcra_central_deudores', 'validacion_cuit_en' => $fuente['consultado_en'], 'updated_at' => now()]);
            $actualizadas++;
            $auditoria->handle(null, null, 'validacion_cuit_comprador', (string) $fila->usuario_id, 'revalidacion', null,
                ['cuit_hash' => $fila->cuit_hash, 'fuente' => 'bcra_central_deudores', 'estado_fuente' => $fuente['estado'], 'estado_validacion' => $estado]);
        }
        $this->info("Perfiles revalidados: {$actualizadas}");

        return self::SUCCESS;
    }
}
