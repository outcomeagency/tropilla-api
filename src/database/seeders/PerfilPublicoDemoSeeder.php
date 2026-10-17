<?php

namespace Database\Seeders;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** TPA-78: opt-in de demo + pedigrí sintético, acotado a slugs y cuentas demo conocidos. */
final class PerfilPublicoDemoSeeder extends Seeder
{
    private const SLUGS = ['la-esperanza', 'el-timbo', 'los-caranday', 'don-anselmo', 'la-paloma', 'santa-rita'];

    private const NAMESPACE = 'c24d9bb4-a9ea-4b78-9086-00852332c378';

    public function run(): void
    {
        $perfiles = DB::table('perfiles_publicos')->whereIn('slug', self::SLUGS)->get();
        $usuario = DB::table('usuarios')->where('email', 'prueba@tropilla.ar')->first();
        if ($usuario) {
            $ids = DB::table('membresias')->where('usuario_id', $usuario->id)->where('rol', 'dueno')->where('estado', 'activa')->pluck('establecimiento_id');
            $perfiles = $perfiles->merge(DB::table('perfiles_publicos')->whereIn('establecimiento_id', $ids)->get())->unique('establecimiento_id');
        }
        foreach ($perfiles as $perfil) {
            $this->preparar($perfil);
        }
    }

    private function preparar(object $perfil): void
    {
        $est = DB::table('establecimientos')->where('id', $perfil->establecimiento_id)->first();
        if (! $est) {
            return;
        }
        $ficha = json_decode((string) $est->ficha_ampliada, true) ?: [];
        $visibilidad = json_decode((string) $est->visibilidad_ficha, true) ?: [];
        $fichaOriginal = $ficha;
        $visibilidadOriginal = $visibilidad;
        $ficha['sanidad_renspa'] ??= [];
        $ficha['sanidad_renspa'] += ['plan_sanitario' => 'Plan sanitario declarado: aftosa, brucelosis y control de garrapata.', 'fecha' => '2026-10-01'];
        if (! array_key_exists('sanidad_renspa', $visibilidad)) {
            $visibilidad['sanidad_renspa'] = true;
        }
        if ($ficha !== $fichaOriginal || $visibilidad !== $visibilidadOriginal) {
            $modelo = Establecimiento::query()->find($est->id);
            $modelo->ficha_ampliada = $ficha;
            $modelo->visibilidad_ficha = $visibilidad;
            $modelo->row_version++;
            $modelo->save();
            app(RegistrarCambio::class)->handle($est->id, 'establecimiento', $est->id, cadena: Cadena::bloquearDeEstablecimiento($est->id));
        }
        $esp = DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->orderBy('especie_codigo')->value('especie_codigo') ?: 'BOV';
        $raza = $esp === 'BUB' ? 'Murrah (demo)' : 'Braford (demo)';
        $niveles = [];
        for ($gen = 1; $gen <= 5; $gen++) {
            $niveles[$gen] = [];
            $cantidad = 2 ** (6 - $gen);
            for ($i = 0; $i < $cantidad; $i++) {
                $rp = 'TPA78-'.strtoupper($perfil->slug)."-{$esp}-G{$gen}-".str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $id = Uuid::uuid5(Uuid::fromString(self::NAMESPACE), $rp)->toString();
                $madre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2) % count($niveles[$gen - 1])];
                $padre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2 + 1) % count($niveles[$gen - 1])];
                if ($gen === 3 && $i === 2) {
                    $padre = $niveles[$gen - 1][0];
                }
                $datos = ['nombre' => ($esp === 'BUB' ? 'Reproductor bubalino' : 'Reproductor bovino')." G{$gen}-".($i + 1), 'rp' => $rp,
                    'registro_genealogico' => 'REG-'.substr(hash('sha256', $rp), 0, 12), 'raza' => $raza, 'especie_codigo' => $esp,
                    'sexo' => $gen === 5 ? ($i === 0 ? 'M' : 'H') : null, 'centro' => 'Centro de genética regional (declarado)',
                    'fecha_nacimiento' => $gen === 5 ? ($i === 0 ? '2020-06-15' : '2021-03-20') : null,
                    'peso_kg' => $gen === 5 ? ($i === 0 ? 710 : 525) : null, 'peso_fecha' => $gen === 5 ? '2026-09-12' : null,
                    'cc_valor' => $gen === 5 ? ($i === 0 ? 6 : 5) : null, 'partos' => $gen === 5 && $i === 1 ? 3 : null,
                    'madre_externa_id' => $madre, 'padre_externa_id' => $padre];
                DB::table('reproductores_externos')->insertOrIgnore(['id' => $id, 'establecimiento_id' => $est->id, ...$datos, 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('reproductor_externo_versiones')->insertOrIgnore(['reproductor_id' => $id, 'establecimiento_id' => $est->id, 'version' => 1, 'accion' => 'alta', 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'autor_id' => null, 'created_at' => now()]);
                $niveles[$gen][] = $id;
            }
        }
        $config = json_decode($perfil->secciones ?: '{}', true) ?: [];
        if (! (($config['reproductores']['demo_seeded'] ?? false) === true)) {
            $elegidos = $config['reproductores']['ids'] ?? [];
            $config['reproductores'] = [...($config['reproductores'] ?? []), 'activa' => true, 'ids' => $elegidos ?: [
                ['tipo' => 'externo', 'id' => $niveles[5][0]], ['tipo' => 'externo', 'id' => $niveles[5][1]],
            ], 'demo_seeded' => true];
            foreach (['composicion', 'indicadores', 'galeria'] as $key) {
                if (! array_key_exists('demo_seeded', $config[$key] ?? [])) {
                    $config[$key] = [...($config[$key] ?? []), 'activa' => true, ...($key === 'composicion' ? ['modo' => $config[$key]['modo'] ?? 'rango'] : []), 'demo_seeded' => true];
                }
            }
            DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->update(['secciones' => json_encode($config, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }
}
