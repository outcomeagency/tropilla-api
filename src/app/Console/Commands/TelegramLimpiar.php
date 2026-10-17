<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Cada hora (TPA-77): vencen las propuestas del bot sin confirmar y se podan update_id y códigos viejos. */
class TelegramLimpiar extends Command
{
    protected $signature = 'telegram:limpiar';

    protected $description = 'Vence propuestas del bot sin confirmar y poda updates y códigos viejos';

    public function handle(): int
    {
        $vencidas = DB::table('telegram_propuestas')->whereIn('estado', ['pendiente', 'revisando'])->where('expira_en', '<', now())
            ->update(['estado' => 'expirada', 'updated_at' => now()]);
        DB::table('telegram_updates')->where('recibido_en', '<', now()->subDays(7))->delete();
        DB::table('telegram_codigos')->where('expira_en', '<', now()->subDays(7))->delete();
        $this->info("Propuestas vencidas: {$vencidas}");

        return self::SUCCESS;
    }
}
