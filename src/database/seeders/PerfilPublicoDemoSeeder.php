<?php

namespace Database\Seeders;

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Fotos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * TPA-78: opt-in de demo + pedigrí verosímil, acotado a slugs y cuentas demo conocidos.
 * Idempotente: ids uuid5 por clave estable; los datos de cada reproductor se corrigen con versión nueva si cambian.
 */
final class PerfilPublicoDemoSeeder extends Seeder
{
    private const SLUGS = ['la-esperanza', 'el-timbo', 'los-caranday', 'don-anselmo', 'la-paloma', 'santa-rita'];

    private const NAMESPACE = 'c24d9bb4-a9ea-4b78-9086-00852332c378';

    /** Raza de la genealogía demo por perfil (los demás: Braford o Murrah según la especie). */
    private const RAZA = ['el-timbo' => 'Braford', 'don-anselmo' => 'Brangus', 'la-paloma' => 'Hereford', 'los-caranday' => 'Murrah', 'santa-rita' => 'Mediterránea'];

    private const MACHOS = ['Payador', 'Tacuarí', 'Capataz', 'Ñandubay', 'Guaraní', 'Itatí', 'Mburucuyá', 'Baqueano', 'Rastreador', 'Caudillo', 'Sapucay', 'Yaguareté', 'Chamamé', 'Paraná', 'Corsario', 'Matrero'];

    private const HEMBRAS = ['Pampa', 'Aurora', 'Morena', 'Paloma', 'Estrella', 'Lucera', 'Brisa', 'Calandria', 'Tormenta', 'Golondrina', 'Azucena', 'Margarita', 'Pitanga', 'Camelia', 'Rosada', 'Totora'];

    private const CENTROS = ['Cabaña propia', 'Centro de genética del NEA', 'Cabaña de la zona', 'Inseminación con semen importado'];

    /** Foto del toro y de la hembra destacados por raza (database/seeders/media). */
    private const FOTOS = ['Braford' => ['rep-braford-m', 'rep-braford-h'], 'Brangus' => ['rep-brangus-m', 'rep-brangus-h'],
        'Hereford' => ['rep-hereford-m', 'rep-hereford-h'], 'Murrah' => ['rep-murrah-m', 'rep-murrah-h'], 'Mediterránea' => ['rep-murrah-m', 'rep-murrah-h']];

    private const ALT = [
        'rep-braford-m' => ['es' => 'Toro Braford reproductor en el corral', 'en' => 'Braford breeding bull in the pen', 'pt' => 'Touro Braford reprodutor no curral'],
        'rep-braford-h' => ['es' => 'Vaca Braford madre con su ternero', 'en' => 'Braford cow with her calf', 'pt' => 'Vaca Braford com seu bezerro'],
        'rep-brangus-m' => ['es' => 'Toro Brangus negro reproductor', 'en' => 'Black Brangus breeding bull', 'pt' => 'Touro Brangus preto reprodutor'],
        'rep-brangus-h' => ['es' => 'Vaca Brangus en el monte chaqueño', 'en' => 'Brangus cow in the Chaco woodland', 'pt' => 'Vaca Brangus no mato chaquenho'],
        'rep-hereford-m' => ['es' => 'Toro Hereford reproductor', 'en' => 'Hereford breeding bull', 'pt' => 'Touro Hereford reprodutor'],
        'rep-hereford-h' => ['es' => 'Vaca Hereford con su ternero', 'en' => 'Hereford cow with her calf', 'pt' => 'Vaca Hereford com seu bezerro'],
        'rep-murrah-m' => ['es' => 'Toro bubalino Murrah reproductor', 'en' => 'Murrah breeding buffalo bull', 'pt' => 'Touro bubalino Murrah reprodutor'],
        'rep-murrah-h' => ['es' => 'Búfala Murrah madre en el estero', 'en' => 'Murrah buffalo cow in the marsh', 'pt' => 'Búfala Murrah no banhado'],
        'cp-portada' => ['es' => 'Rodeo Braford al atardecer entre palmares en Caá Catí', 'en' => 'Braford herd at sunset among palms in Caá Catí', 'pt' => 'Rebanho Braford ao entardecer entre palmeiras em Caá Catí'],
        'cp-vientres' => ['es' => 'Vientres Braford con sus terneros en Campo de Prueba', 'en' => 'Braford cows with calves at Campo de Prueba', 'pt' => 'Matrizes Braford com bezerros no Campo de Prueba'],
        'cp-toritos' => ['es' => 'Toritos Braford de recría en el potrero', 'en' => 'Young Braford bulls in the paddock', 'pt' => 'Tourinhos Braford de recria no piquete'],
        'cp-bufalas' => ['es' => 'Búfalas Murrah en el estero, borde del Iberá', 'en' => 'Murrah buffalo cows in the Iberá marsh', 'pt' => 'Búfalas Murrah no banhado do Iberá'],
    ];

    /** @var array<string, string> */
    private array $shas = [];

    public function run(): void
    {
        $perfiles = DB::table('perfiles_publicos')->whereIn('slug', self::SLUGS)->get();
        $usuario = DB::table('usuarios')->where('email', 'prueba@tropilla.ar')->first();
        $prueba = collect();
        if ($usuario) {
            $ids = DB::table('membresias')->where('usuario_id', $usuario->id)->where('rol', 'dueno')->where('estado', 'activa')->pluck('establecimiento_id');
            $prueba = DB::table('perfiles_publicos')->whereIn('establecimiento_id', $ids)->get();
            $perfiles = $perfiles->merge($prueba)->unique('establecimiento_id');
        }
        foreach ($perfiles as $perfil) {
            $this->preparar($perfil);
        }
        foreach ($prueba as $perfil) {
            $this->galeriaCampoDePrueba($perfil);
        }
        $this->genealogiaRodeoLaEsperanza();
    }

    /** Ancestros externos del rodeo de La Esperanza (DemoRegionalSeeder, TPA-76): nombres y datos verosímiles; la clave estable es el registro declarado. */
    private function genealogiaRodeoLaEsperanza(): void
    {
        $filas = DB::table('reproductores_externos')->where('registro_genealogico', 'like', 'BRAF-NEA-%')->whereNull('archivado_en')->get();
        foreach ($filas as $a) {
            if (! preg_match('/^BRAF-NEA-(\d)-(\d{3})$/', (string) $a->registro_genealogico, $m)) {
                continue;
            }
            [$gen, $i] = [(int) $m[1], (int) $m[2] - 1];
            $esMadre = DB::table('reproductores_externos')->where('madre_externa_id', $a->id)->exists() || DB::table('animales_estado')->where('madre_externa_id', $a->id)->exists();
            $esPadre = DB::table('reproductores_externos')->where('padre_externa_id', $a->id)->exists() || DB::table('animales_estado')->where('padre_externo_id', $a->id)->exists();
            $macho = $esPadre || (! $esMadre && $i % 2 === 1);
            $semilla = crc32((string) $a->registro_genealogico);
            $numero = 100 + ($semilla % 800);
            $nombres = $macho ? self::MACHOS : self::HEMBRAS;
            $datos = ['nombre' => $nombres[($i + $gen * 5) % count($nombres)].' '.$numero, 'rp' => "LE-{$numero}", 'sexo' => $macho ? 'M' : 'H',
                'fecha_nacimiento' => sprintf('%d-%02d-%02d', [1 => 2005, 2 => 2009, 3 => 2013, 4 => 2017, 5 => 2020][$gen] + ($i % 2), 3 + $semilla % 8, 1 + $semilla % 27),
                'centro' => self::CENTROS[($gen + $i) % count(self::CENTROS)]];
            $this->guardarReproductor($a->id, $a->establecimiento_id, [...array_intersect_key((array) $a, array_flip(['nombre', 'rp', 'registro_genealogico', 'raza', 'especie_codigo', 'sexo', 'centro', 'fecha_nacimiento', 'madre_externa_id', 'padre_externa_id'])), ...$datos]);
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
        $espViejo = DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->orderBy('especie_codigo')->value('especie_codigo') ?: 'BOV';
        $raza = self::RAZA[$perfil->slug] ?? ($espViejo === 'BUB' ? 'Murrah' : 'Braford');
        $esp = in_array($raza, ['Murrah', 'Mediterránea'], true) ? 'BUB' : 'BOV';
        $sigla = collect(preg_split('/\s+/', (string) $est->nombre))->filter(fn ($w) => mb_strlen($w) > 1)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'TR';
        $dueno = $this->dueno($est->id);
        $niveles = [];
        for ($gen = 1; $gen <= 5; $gen++) {
            $niveles[$gen] = [];
            $cantidad = 2 ** (6 - $gen);
            for ($i = 0; $i < $cantidad; $i++) {
                // Clave de la primera versión del seeder: mantiene los mismos ids.
                $clave = 'TPA78-'.strtoupper($perfil->slug)."-{$espViejo}-G{$gen}-".str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $id = Uuid::uuid5(Uuid::fromString(self::NAMESPACE), $clave)->toString();
                $madre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2) % count($niveles[$gen - 1])];
                $padre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2 + 1) % count($niveles[$gen - 1])];
                if ($gen === 3 && $i === 2) {
                    $padre = $niveles[$gen - 1][0];
                }
                // Las hembras ocupan posiciones pares (son las madres de la generación siguiente); en G5, 0 = toro, 1 = hembra.
                $macho = $gen === 5 ? $i === 0 : $i % 2 === 1;
                $semilla = crc32($clave);
                $nombres = $macho ? self::MACHOS : self::HEMBRAS;
                $numero = 100 + ($semilla % 800);
                $anio = [1 => 2004, 2 => 2008, 3 => 2012, 4 => 2016, 5 => $macho ? 2020 : 2021][$gen] + ($i % 2);
                $bufalo = $esp === 'BUB';
                $peso = $macho ? ($bufalo ? 820 : 760) + ($semilla % 140) : ($bufalo ? 560 : 480) + ($semilla % 90);
                $datos = ['nombre' => $nombres[($i + $gen * 3 + crc32($perfil->slug)) % count($nombres)].' '.$numero, 'rp' => "{$sigla}-{$numero}", 'registro_genealogico' => 'Declarado '.(40000 + $semilla % 9000),
                    'raza' => $raza, 'especie_codigo' => $esp, 'sexo' => $macho ? 'M' : 'H', 'centro' => self::CENTROS[($gen + $i) % count(self::CENTROS)],
                    'fecha_nacimiento' => sprintf('%d-%02d-%02d', $anio, 3 + $semilla % 8, 1 + $semilla % 27),
                    'peso_kg' => $peso, 'peso_fecha' => '2026-09-12', 'cc_valor' => $macho ? 6 : 5 + ($semilla % 2),
                    'partos' => $macho ? null : min(9, 2 + (5 - $gen) * 2 + $semilla % 2),
                    'madre_externa_id' => $madre, 'padre_externa_id' => $padre];
                if ($gen === 5 && $dueno && ($foto = $this->foto(self::FOTOS[$raza][$macho ? 0 : 1], $dueno))) {
                    $datos['foto_sha256'] = $foto;
                }
                $this->guardarReproductor($id, $est->id, $datos);
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

    /** Alta o corrección versionada (nunca se borra): solo escribe si los datos cambiaron. */
    private function guardarReproductor(string $id, string $estId, array $datos): void
    {
        $actual = DB::table('reproductores_externos')->where('id', $id)->first();
        if (! $actual) {
            DB::table('reproductores_externos')->insert(['id' => $id, 'establecimiento_id' => $estId, ...$datos, 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reproductor_externo_versiones')->insertOrIgnore(['reproductor_id' => $id, 'establecimiento_id' => $estId, 'version' => 1, 'accion' => 'alta', 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'autor_id' => null, 'created_at' => now()]);

            return;
        }
        $cambio = collect($datos)->contains(fn ($v, $k) => (string) ($actual->{$k} ?? '') !== (string) $v
            && ! (is_numeric($v) && is_numeric($actual->{$k} ?? null) && (float) $v === (float) $actual->{$k}));
        if (! $cambio) {
            return;
        }
        $version = (int) $actual->row_version + 1;
        DB::table('reproductores_externos')->where('id', $id)->update([...$datos, 'row_version' => $version, 'updated_at' => now()]);
        DB::table('reproductor_externo_versiones')->insertOrIgnore(['reproductor_id' => $id, 'establecimiento_id' => $estId, 'version' => $version, 'accion' => 'correccion', 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE), 'autor_id' => null, 'created_at' => now()]);
    }

    /** Portada y galería de Campo de Prueba con fotos reales en lugar de las ilustraciones del script de siembra. */
    private function galeriaCampoDePrueba(object $perfil): void
    {
        $dueno = $this->dueno($perfil->establecimiento_id);
        if (! $dueno) {
            return;
        }
        $shas = array_values(array_filter(array_map(fn ($n) => $this->foto($n, $dueno), ['cp-portada', 'cp-vientres', 'cp-toritos', 'cp-bufalas'])));
        if ($shas === []) {
            return;
        }
        DB::table('perfiles_publicos')->where('establecimiento_id', $perfil->establecimiento_id)->update(['fotos' => '{'.implode(',', $shas).'}', 'updated_at' => now()]);
    }

    private function dueno(string $estId): ?User
    {
        $id = DB::table('membresias')->where('establecimiento_id', $estId)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id');

        return $id ? User::query()->find($id) : null;
    }

    /** Guarda la foto (idempotente por hash) con dimensiones y alt; devuelve el SHA-256 (null si el archivo no está). */
    private function foto(string $nombre, User $u): ?string
    {
        if (isset($this->shas[$nombre])) {
            return $this->shas[$nombre];
        }
        if (! is_file(__DIR__."/media/{$nombre}.webp")) {
            return null;
        }
        $bytes = (string) file_get_contents(__DIR__."/media/{$nombre}.webp");
        $sha = app(GuardarArchivo::class)->handle($bytes, 'image/webp', $u);
        [$ancho, $alto] = Fotos::dimensiones($bytes);
        DB::table('archivos')->where('sha256', $sha)->update(['ancho' => $ancho, 'alto' => $alto, 'alt' => json_encode(self::ALT[$nombre], JSON_UNESCAPED_UNICODE)]);

        return $this->shas[$nombre] = $sha;
    }
}
