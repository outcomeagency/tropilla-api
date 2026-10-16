<?php

namespace App\Console\Commands;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\GeneradorAlertas;
use App\Domain\Notificaciones\Notificador;
use App\Domain\Notificaciones\ResumenDiarioMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Diario 07:00 ART (TPA-53): alertas por establecimiento y, después, el resumen diario por email a quien lo activó. */
class GenerarAlertas extends Command
{
    protected $signature = 'alertas:generar {--sin-resumen}';

    protected $description = 'Genera las alertas del día (in-app + push) y envía el resumen diario por email';

    public function handle(GeneradorAlertas $generador, Notificador $notificador): int
    {
        $n = 0;
        foreach (Establecimiento::query()->whereNull('archivado_en')->cursor() as $est) {
            $n += $generador->generar($est);
        }
        $mails = 0;
        if (! $this->option('sin-resumen')) {
            $porUsuario = DB::table('notificaciones')->whereNull('email_en')->where('created_at', '>=', now()->subDay())->orderBy('created_at')->get()->groupBy('usuario_id');
            foreach ($porUsuario as $usuarioId => $notifs) {
                $incluir = $notifs->filter(fn ($x) => $notificador->preferencia($usuarioId, $x->establecimiento_id, $x->tipo, 'resumen_diario'));
                $u = User::query()->find($usuarioId);
                if ($incluir->isEmpty() || ! $u?->email) {
                    continue;
                }
                $u->notify(new ResumenDiarioMail($incluir->map(fn ($x) => ['titulo' => $x->titulo, 'cuerpo' => $x->cuerpo])->values()->all()));
                DB::table('notificaciones')->whereIn('id', $incluir->pluck('id'))->update(['email_en' => now()]);
                $mails++;
            }
        }
        $this->info("Alertas nuevas: {$n} · resúmenes enviados: {$mails}");

        return self::SUCCESS;
    }
}
