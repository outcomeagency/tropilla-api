<?php

namespace App\Domain\Telegram;

use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vinculación chat de Telegram ↔ usuario (TPA-77). El panel o la app generan un código de un solo uso y vida corta
 * (solo se guarda su SHA-256); en Telegram `/start <código>` (deep link `t.me/<bot>?start=<código>`) lo canjea.
 * Un vínculo activo por usuario y por chat: vincular de nuevo reemplaza el anterior. Todo queda en la auditoría.
 */
final class Vinculos
{
    /** Sin 0/O, 1/I/L: se puede dictar o tipear sin confundir. */
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LARGO = 10;

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @return array{codigo: string, expira_en: Carbon} */
    public function generarCodigo(User $usuario): array
    {
        $codigo = '';
        for ($i = 0; $i < self::LARGO; $i++) {
            $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
        }
        $expira = now()->addMinutes((int) config('tropilla.telegram.codigo_minutos'));
        DB::transaction(function () use ($usuario, $codigo, $expira) {
            // Un código vigente por usuario: generar otro invalida el anterior.
            DB::table('telegram_codigos')->where('usuario_id', $usuario->id)->whereNull('usado_en')->where('expira_en', '>', now())->update(['expira_en' => now()]);
            DB::table('telegram_codigos')->insert(['id' => (string) Str::uuid7(), 'usuario_id' => $usuario->id, 'codigo_hash' => self::hash($codigo), 'expira_en' => $expira, 'created_at' => now()]);
        });

        return ['codigo' => $codigo, 'expira_en' => $expira];
    }

    /**
     * Canjea el código y vincula el chat. null = código inexistente, usado o vencido (no se distingue).
     *
     * @param  array{id: int, username?: string|null}  $de  remitente de Telegram
     */
    public function canjear(string $codigo, int $chatId, array $de): ?object
    {
        $codigo = strtoupper(trim($codigo));
        if (! preg_match('/^['.self::ALFABETO.']{'.self::LARGO.'}$/', $codigo)) {
            return null;
        }

        return DB::transaction(function () use ($codigo, $chatId, $de) {
            $c = DB::table('telegram_codigos')->where('codigo_hash', self::hash($codigo))->lockForUpdate()->first();
            if ($c === null || $c->usado_en !== null || now()->gte($c->expira_en)) {
                return null;
            }
            $usuario = User::query()->find($c->usuario_id);
            if ($usuario === null || $usuario->bloqueado_en !== null) {
                return null;
            }
            DB::table('telegram_codigos')->where('id', $c->id)->update(['usado_en' => now()]);
            $anteriores = DB::table('telegram_vinculos')->whereNull('desvinculado_en')
                ->where(fn ($q) => $q->where('chat_id', $chatId)->orWhere('usuario_id', $usuario->id))->get();
            foreach ($anteriores as $a) {
                $this->cerrar($a, 'nuevo_vinculo');
            }
            $establecimientos = self::establecimientos($usuario->id);
            $id = (string) Str::uuid7();
            DB::table('telegram_vinculos')->insert([
                'id' => $id, 'usuario_id' => $usuario->id, 'chat_id' => $chatId, 'telegram_usuario_id' => (int) $de['id'],
                'username' => isset($de['username']) ? mb_substr((string) $de['username'], 0, 64) : null,
                'establecimiento_id' => $establecimientos->count() === 1 ? $establecimientos->first()->id : null,
                'avisos' => true, 'vinculado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditoria->handle(null, $usuario->id, 'telegram_vinculo', $id, 'alta', null, ['username' => $de['username'] ?? null]);

            return DB::table('telegram_vinculos')->where('id', $id)->first();
        });
    }

    /** @param 'usuario_bot'|'usuario_panel'|'sistema' $por */
    public function desvincular(object $vinculo, string $por): void
    {
        DB::transaction(fn () => $this->cerrar($vinculo, $por));
    }

    public function elegirEstablecimiento(object $vinculo, string $establecimientoId): bool
    {
        if (! self::establecimientos($vinculo->usuario_id)->contains('id', $establecimientoId)) {
            return false;
        }
        DB::table('telegram_vinculos')->where('id', $vinculo->id)->update(['establecimiento_id' => $establecimientoId, 'updated_at' => now()]);
        $this->auditoria->handle($establecimientoId, $vinculo->usuario_id, 'telegram_vinculo', $vinculo->id, 'establecimiento_activo',
            ['establecimiento_id' => $vinculo->establecimiento_id], ['establecimiento_id' => $establecimientoId]);

        return true;
    }

    public function guardarTelefono(object $vinculo, string $telefono): void
    {
        $tel = mb_substr(preg_replace('/[^\d+]/', '', $telefono) ?? '', 0, 20);
        DB::table('telegram_vinculos')->where('id', $vinculo->id)->update(['telefono' => $tel !== '' ? $tel : null, 'updated_at' => now()]);
        $this->auditoria->handle(null, $vinculo->usuario_id, 'telegram_vinculo', $vinculo->id, 'telefono', null, ['telefono' => $tel !== '' ? '***'.substr($tel, -3) : null]);
    }

    public function avisos(object $vinculo, bool $activos): void
    {
        DB::table('telegram_vinculos')->where('id', $vinculo->id)->update(['avisos' => $activos, 'updated_at' => now()]);
        $this->auditoria->handle(null, $vinculo->usuario_id, 'telegram_vinculo', $vinculo->id, 'avisos', ['avisos' => (bool) $vinculo->avisos], ['avisos' => $activos]);
    }

    public static function deChat(int $chatId): ?object
    {
        return DB::table('telegram_vinculos')->where('chat_id', $chatId)->whereNull('desvinculado_en')->first();
    }

    public static function deUsuario(string $usuarioId): ?object
    {
        return DB::table('telegram_vinculos')->where('usuario_id', $usuarioId)->whereNull('desvinculado_en')->first();
    }

    /** Establecimientos con membresía activa (no archivados), por nombre. @return Collection<int, object{id: string, nombre: string, rol: string}> */
    public static function establecimientos(string $usuarioId): Collection
    {
        return DB::table('membresias as m')->join('establecimientos as e', 'e.id', '=', 'm.establecimiento_id')
            ->where('m.usuario_id', $usuarioId)->where('m.estado', 'activa')->whereNull('e.archivado_en')
            ->orderBy('e.nombre')->get(['e.id', 'e.nombre', 'm.rol']);
    }

    public static function hash(string $codigo): string
    {
        return hash('sha256', strtoupper($codigo));
    }

    private function cerrar(object $vinculo, string $por): void
    {
        $n = DB::table('telegram_vinculos')->where('id', $vinculo->id)->whereNull('desvinculado_en')
            ->update(['desvinculado_en' => now(), 'desvinculado_por' => $por, 'updated_at' => now()]);
        if ($n === 0) {
            return;
        }
        DB::table('telegram_propuestas')->where('vinculo_id', $vinculo->id)->whereIn('estado', ['pendiente', 'leyendo', 'revisando'])
            ->update(['estado' => 'cancelada', 'updated_at' => now()]);
        $this->auditoria->handle(null, $vinculo->usuario_id, 'telegram_vinculo', $vinculo->id, 'baja', null, ['por' => $por]);
    }
}
