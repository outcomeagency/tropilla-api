<?php

namespace Database\Seeders;

use App\Domain\Comercial\Actions\GestionarOferta;
use App\Domain\Comercial\Models\Oferta;
use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Fotos;
use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarPotrero;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Perfil\Actions\GestionarPerfil;
use App\Domain\Perfil\Services\Perfiles;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contenido DEMO del directorio público con fotos (bugfix TPA-54). Sobre el demo «La Esperanza» agrega su portada, la foto de
 * la oferta de bubillos, el retrato de Ramón y fotos de animales; y crea 5 establecimientos más (perfil publicado, en el
 * directorio, textos ES/EN/PT, portada) con hacienda mínima cargada **por eventos** (`RegistrarEvento`: integridad genuina),
 * 7 ofertas publicadas con foto y 3 profesionales públicos. Las fotos (generadas con IA, personas ficticias) viven en
 * `database/seeders/media/` y se guardan como archivos direccionados por SHA-256 (misma vía que TPA-44), con alt ES/EN/PT.
 *
 * Idempotente: cada establecimiento se crea una sola vez (si su dueño ya tiene membresía, no se toca); fotos y profesionales
 * se aseguran en cada corrida. Requiere DemoLaEsperanzaSeeder.
 *
 *   docker compose exec app php artisan db:seed --class=DemoDirectorioSeeder
 */
class DemoDirectorioSeeder extends Seeder
{
    public const PASSWORD = 'tropilla-demo';

    /** @var array<string, array{es: string, en: string, pt: string}> */
    public const ALT = [
        'est-la-esperanza' => ['es' => 'Búfalos y vacas pastando en los humedales de La Esperanza, General Paz', 'en' => 'Buffalo and cattle grazing in the wetlands of La Esperanza, General Paz', 'pt' => 'Búfalos e bovinos pastando nas áreas úmidas de La Esperanza, General Paz'],
        'est-el-timbo' => ['es' => 'Rodeo Braford bajo un timbó en El Timbó, Ituzaingó', 'en' => 'Braford herd under a timbó tree at El Timbó, Ituzaingó', 'pt' => 'Rebanho Braford sob um timbó em El Timbó, Ituzaingó'],
        'est-los-caranday' => ['es' => 'Búfalas Murrah entre palmares de caranday en Pirané, Formosa', 'en' => 'Murrah buffalo cows among caranday palms in Pirané, Formosa', 'pt' => 'Búfalas Murrah entre palmeiras caranday em Pirané, Formosa'],
        'est-don-anselmo' => ['es' => 'Vacas Brangus en el monte chaqueño de Don Anselmo', 'en' => 'Brangus cows in the Chaco woodland at Don Anselmo', 'pt' => 'Vacas Brangus no mato chaquenho de Don Anselmo'],
        'est-la-paloma' => ['es' => 'Vacas Hereford en las lomas coloradas de La Paloma, Apóstoles', 'en' => 'Hereford cows on the red hills of La Paloma, Apóstoles', 'pt' => 'Vacas Hereford nas colinas vermelhas de La Paloma, Apóstoles'],
        'est-santa-rita' => ['es' => 'Búfalos Mediterráneos y Jafarabadi en los esteros de Santa Rita, Mercedes', 'en' => 'Mediterranean and Jafarabadi buffalo in the marshes of Santa Rita, Mercedes', 'pt' => 'Búfalos Mediterrâneos e Jafarabadi nos banhados de Santa Rita, Mercedes'],
        'of-bubillos-mediterraneos' => ['es' => 'Bubillos Mediterráneos terminados a pasto', 'en' => 'Grass-finished Mediterranean buffalo steers', 'pt' => 'Bubilhos Mediterrâneos terminados a pasto'],
        'of-bufalas-murrah-prenadas' => ['es' => 'Búfalas Murrah preñadas en el potrero', 'en' => 'Pregnant Murrah buffalo cows in the paddock', 'pt' => 'Búfalas Murrah prenhes no piquete'],
        'of-toros-jafarabadi' => ['es' => 'Toros búfalos Jafarabadi', 'en' => 'Jafarabadi buffalo bulls', 'pt' => 'Touros búfalos Jafarabadi'],
        'of-bucerros-castrados' => ['es' => 'Bucerros castrados de 230 a 250 kg', 'en' => 'Castrated buffalo calves, 230 to 250 kg', 'pt' => 'Bezerros bubalinos castrados de 230 a 250 kg'],
        'of-terneros-braford' => ['es' => 'Terneros Braford de destete', 'en' => 'Weaned Braford calves', 'pt' => 'Bezerros Braford desmamados'],
        'of-novillos-braford' => ['es' => 'Novillos Braford terminados', 'en' => 'Finished Braford steers', 'pt' => 'Novilhos Braford terminados'],
        'of-vaquillonas-brangus' => ['es' => 'Vaquillonas Brangus para entorar', 'en' => 'Brangus heifers ready for breeding', 'pt' => 'Novilhas Brangus para cobertura'],
        'of-vacas-hereford' => ['es' => 'Vacas Hereford preñadas', 'en' => 'Pregnant Hereford cows', 'pt' => 'Vacas Hereford prenhes'],
        'an-bufala-murrah' => ['es' => 'Búfala Murrah U-0142', 'en' => 'Murrah buffalo cow U-0142', 'pt' => 'Búfala Murrah U-0142'],
        'an-vaca-braford' => ['es' => 'Vaca Braford 1187', 'en' => 'Braford cow 1187', 'pt' => 'Vaca Braford 1187'],
        'an-toro-bufalo' => ['es' => 'Toro búfalo Jafarabadi', 'en' => 'Jafarabadi buffalo bull', 'pt' => 'Touro búfalo Jafarabadi'],
        'an-bucerra' => ['es' => 'Bucerra Murrah destetada', 'en' => 'Weaned Murrah buffalo heifer calf', 'pt' => 'Bezerra bubalina Murrah desmamada'],
        'per-productor' => ['es' => 'Retrato de Ramón Gómez, productor', 'en' => 'Portrait of Ramón Gómez, rancher', 'pt' => 'Retrato de Ramón Gómez, produtor'],
        'per-veterinaria' => ['es' => 'Retrato de la médica veterinaria Carolina Aguirre', 'en' => 'Portrait of veterinarian Carolina Aguirre', 'pt' => 'Retrato da médica veterinária Carolina Aguirre'],
        'per-agronomo' => ['es' => 'Retrato del ingeniero agrónomo Martín Sosa', 'en' => 'Portrait of agronomist Martín Sosa', 'pt' => 'Retrato do engenheiro agrônomo Martín Sosa'],
        'per-asesora' => ['es' => 'Retrato de la asesora ganadera Lucía Fernández', 'en' => 'Portrait of livestock consultant Lucía Fernández', 'pt' => 'Retrato da consultora pecuária Lucía Fernández'],
    ];

    private Establecimiento $est;

    private User $dueno;

    /** @var array<string, string> */
    private array $shas = [];

    public function run(): void
    {
        $ramon = User::query()->where('email', DemoLaEsperanzaSeeder::EMAIL)->first();
        if ($ramon === null || ! DB::table('membresias')->where('usuario_id', $ramon->id)->where('estado', 'activa')->exists()) {
            $this->command?->warn('Falta el demo La Esperanza: correr antes DemoLaEsperanzaSeeder.');

            return;
        }
        DB::transaction(function () use ($ramon) {
            $this->laEsperanza($ramon);
            foreach (self::campos() as $c) {
                $this->campo($c);
            }
            $this->profesionales();
        });
        $this->command?->info('Demo del directorio cargado (5 establecimientos, 8 ofertas con foto, 3 profesionales; usuarios @tropilla.test / '.self::PASSWORD.').');
    }

    /** Guarda la foto (idempotente por hash) con dimensiones y alt; devuelve el SHA-256. */
    private function foto(string $nombre, User $u): string
    {
        if (isset($this->shas[$nombre])) {
            return $this->shas[$nombre];
        }
        $bytes = (string) file_get_contents(__DIR__."/media/{$nombre}.webp");
        $sha = app(GuardarArchivo::class)->handle($bytes, 'image/webp', $u);
        [$ancho, $alto] = Fotos::dimensiones($bytes);
        DB::table('archivos')->where('sha256', $sha)->update(['ancho' => $ancho, 'alto' => $alto, 'alt' => json_encode(self::ALT[$nombre], JSON_UNESCAPED_UNICODE)]);

        return $this->shas[$nombre] = $sha;
    }

    private function laEsperanza(User $ramon): void
    {
        $this->dueno = $ramon;
        $this->est = Establecimiento::query()->whereIn('id', DB::table('membresias')->where('usuario_id', $ramon->id)->where('rol', 'dueno')->where('estado', 'activa')->select('establecimiento_id'))
            ->where('nombre', 'La Esperanza')->firstOrFail();

        $ramon->forceFill(['foto_sha256' => $this->foto('per-productor', $ramon)])->save();
        $this->portada('est-la-esperanza');

        $oferta = Oferta::query()->where('establecimiento_id', $this->est->id)->where('especie_codigo', 'BUB')->where('tipo', 'gordo')
            ->whereIn('estado', ['publicada', 'reservada'])->orderBy('created_at')->first();
        if ($oferta !== null) {
            $this->fotoDeOferta($oferta, 'of-bubillos-mediterraneos');
        }

        $porIdent = fn (string $tipo, string $valor) => DB::table('animal_identificaciones')->where('establecimiento_id', $this->est->id)
            ->where('tipo', $tipo)->where('valor', $valor)->where('vigente', true)->value('animal_id');
        foreach ([['tatuaje', 'U-0142', 'an-bufala-murrah'], ['caravana_visual', '1187', 'an-vaca-braford'], ['caravana_visual', 'B26-001', 'an-bucerra']] as [$tipo, $valor, $foto]) {
            if ($id = $porIdent($tipo, $valor)) {
                $this->fotoDeAnimal($id, $foto);
            }
        }
    }

    /** Portada del perfil (primera foto). */
    private function portada(string $foto): void
    {
        $sha = $this->foto($foto, $this->dueno);
        $p = Perfiles::asegurar($this->est);
        if (str_contains((string) $p->fotos, $sha)) {
            return;
        }
        app(GestionarPerfil::class)->editar($this->est, $this->dueno, ['row_version' => (int) $p->row_version, 'fotos' => [$sha]]);
    }

    /** Foto en la oferta y nueva publicación (la ficha pública muestra lo congelado en la publicación vigente). */
    private function fotoDeOferta(Oferta $oferta, string $foto): void
    {
        $sha = $this->foto($foto, $this->dueno);
        $vigente = DB::table('oferta_publicaciones')->where('oferta_id', $oferta->id)->where('version', $oferta->publicacion_vigente)->value('contenido');
        if ($vigente !== null && in_array($sha, json_decode($vigente, true)['fotos'] ?? [], true)) {
            return;
        }
        $g = app(GestionarOferta::class);
        $oferta = $g->editar($oferta, $this->dueno, ['row_version' => $oferta->row_version, 'fotos' => [$sha]]);
        $g->publicar($oferta, $this->dueno, true);
    }

    /** La foto del animal es un dato del animal: va por evento (`hacienda.actualizacion_ficha`), no por update de la proyección. */
    private function fotoDeAnimal(string $animalId, string $foto): void
    {
        $sha = $this->foto($foto, $this->dueno);
        if (DB::table('animales_estado')->where('animal_id', $animalId)->value('foto_sha256') === $sha) {
            return;
        }
        $this->evento('hacienda.actualizacion_ficha', ['tipo' => 'animales', 'animal_ids' => [$animalId]], ['cambios' => ['foto' => $sha]],
            now()->setTimezone($this->est->zona_horaria)->toDateString());
    }

    /** @param array<string, mixed> $c */
    private function campo(array $c): void
    {
        $this->dueno = User::query()->firstOrCreate(['email' => $c['email']], [
            'nombre' => $c['usuario'], 'password' => self::PASSWORD, 'email_verificado_en' => now(), 'idioma' => 'es',
        ]);
        if (DB::table('membresias')->where('usuario_id', $this->dueno->id)->where('estado', 'activa')->exists()) {
            return; // ya cargado
        }
        $this->est = app(RealizarOnboarding::class)->handle($this->dueno, [
            'productor' => ['tipo' => $c['productor_tipo'], 'nombre' => $c['productor'], 'contacto' => ['email' => $c['email'], 'whatsapp' => $c['whatsapp']]],
            'establecimiento' => [
                'nombre' => $c['nombre'], 'provincia_codigo' => $c['provincia'], 'departamento_codigo' => $c['departamento'], 'localidad' => $c['localidad'],
                'ubicacion' => $c['ubicacion'], 'superficie_ha' => $c['superficie_ha'], 'actividades' => $c['actividades'], 'especies' => $c['especies'],
                'instalaciones' => ['manga', 'balanza', 'embarcadero', 'aguadas'], 'historia' => $c['textos']['es']['historia'],
            ],
        ]);
        $potrero = app(GuardarPotrero::class)->crear($this->est, $this->dueno, $c['potrero'])['potrero']->id;

        $lotes = [];
        foreach ($c['lotes'] as $clave => $l) {
            $lotes[$clave] = $this->lote($l, $potrero);
        }

        $g = app(GestionarOferta::class);
        foreach ($c['ofertas'] as $o) {
            $oferta = $g->crear($this->est, $this->dueno, [
                'tipo' => $o['tipo'], 'lote_id' => $lotes[$o['lote']], 'fotos' => [$this->foto($o['foto'], $this->dueno)],
                'condiciones' => ['modalidad' => 'en_pie', 'mostrar_precio' => false, 'lugar_carga' => $c['localidad']], 'textos' => $o['textos'],
            ]);
            $g->publicar($oferta, $this->dueno, true);
        }
        if (isset($c['foto_animal'])) {
            [$loteClave, $foto] = $c['foto_animal'];
            $this->fotoDeAnimal(DB::table('animales_estado')->where('lote_id', $lotes[$loteClave])->orderBy('animal_id')->value('animal_id'), $foto);
        }

        $perfil = app(GestionarPerfil::class);
        $p = Perfiles::asegurar($this->est);
        $perfil->editar($this->est, $this->dueno, [
            'row_version' => (int) $p->row_version, 'slug' => $c['slug'], 'en_directorio' => true, 'contacto_visible' => ['whatsapp' => true],
            'textos' => $c['textos'], 'fotos' => [$this->foto($c['portada'], $this->dueno)],
        ]);
        $perfil->aceptarPreview($this->est, $this->dueno);
        $perfil->publicar($this->est, $this->dueno);
    }

    /**
     * Lote de animales individuales: alta, traslado al potrero, un pesaje por animal (repartido entre min y max),
     * tacto opcional y vacunación antiaftosa del lote. Todo por eventos.
     *
     * @param  array<string, mixed>  $l
     */
    private function lote(array $l, string $potrero): string
    {
        $loteId = (string) Str::uuid7();
        DB::table('lotes')->insert([
            'id' => $loteId, 'establecimiento_id' => $this->est->id, 'nombre' => $l['nombre'], 'especie_codigo' => $l['especie'],
            'proposito' => $l['proposito'], 'anonimo' => false, 'row_version' => 1, 'creado_por' => $this->dueno->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(RegistrarCambio::class)->handle($this->est->id, 'lote', $loteId);

        $animales = [];
        for ($i = 1; $i <= $l['n']; $i++) {
            $animales[] = array_filter([
                'animal_id' => (string) Str::uuid7(), 'especie' => $l['especie'], 'sexo' => $l['sexo'], 'categoria' => $l['categoria'], 'raza' => $l['raza'],
                'castrado' => $l['castrado'] ?? null, 'denticion' => $l['denticion'] ?? null, 'lote_id' => $loteId,
                'fecha_nacimiento' => now()->setDate(...array_map('intval', explode('-', $l['nacimiento'])))->addDays(($i * 3) % 45)->toDateString(), 'nacimiento_estimado' => true,
                'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => $l['prefijo'].str_pad((string) $i, 3, '0', STR_PAD_LEFT)]],
            ], fn ($v) => $v !== null);
        }
        $ids = array_column($animales, 'animal_id');
        sort($ids);
        $todos = ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $loteId];
        $this->evento('hacienda.alta', ['tipo' => 'animales', 'animal_ids' => $ids], ['motivo' => 'inventario_inicial', 'animales' => $animales], '2026-03-02');
        $this->evento('hacienda.traslado', $todos, ['potrero_destino_id' => $potrero], '2026-03-03');
        $this->evento('sanidad.vacunacion', $todos, ['producto_nombre' => 'Vacuna antiaftosa oleosa', 'enfermedad' => 'aftosa', 'numero_dosis' => 'anual',
            'campana' => '2026-1', 'via' => 'subcutanea', 'retiro_carne_dias' => 0], '2026-05-12');
        [$min, $max] = $l['peso'];
        foreach ($animales as $i => $a) {
            $kg = $min + (int) round(($max - $min) * $i / max(1, $l['n'] - 1));
            $uno = ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]];
            $this->evento('hacienda.pesaje', $uno, ['modo' => 'individual', 'peso_kg' => (string) $kg, 'metodo' => 'balanza'], '2026-10-06');
            if ($l['tacto'] ?? false) {
                $this->evento('reproduccion.tacto', $uno, ['metodo' => 'ecografia', 'resultado' => 'prenada', 'dias_gestacion' => 110 + 4 * $i], '2026-09-28');
            }
        }

        return $loteId;
    }

    private function profesionales(): void
    {
        $ests = fn (array $slugs) => DB::table('perfiles_publicos')->whereIn('slug', $slugs)->pluck('establecimiento_id')->all();
        foreach ([
            ['email' => 'carolina.aguirre@tropilla.test', 'nombre' => 'Carolina Aguirre', 'slug' => 'carolina-aguirre', 'profesion' => 'veterinario', 'foto' => 'per-veterinaria',
                'matricula' => 'MP 2817 (Corrientes)', 'especialidades' => ['reproducción bubalina', 'sanidad de rodeo', 'ecografía'], 'provincias' => ['18', '34'],
                'bio' => 'Médica veterinaria. Reproducción y sanidad en búfalos y bovinos del NEA: ecografía, planes sanitarios y control de brucelosis y tuberculosis.',
                'trabaja' => ['la-esperanza', 'los-caranday', 'santa-rita']],
            ['email' => 'martin.sosa@tropilla.test', 'nombre' => 'Martín Sosa', 'slug' => 'martin-sosa', 'profesion' => 'ing_agronomo', 'foto' => 'per-agronomo',
                'matricula' => 'MP 1544 (Misiones)', 'especialidades' => ['pasturas subtropicales', 'manejo de pastizales', 'suplementación'], 'provincias' => ['54', '18'],
                'bio' => 'Ingeniero agrónomo. Pasturas, carga animal y planificación forrajera en lomas y bajos del NEA.',
                'trabaja' => ['la-paloma', 'el-timbo']],
            ['email' => 'lucia.fernandez@tropilla.test', 'nombre' => 'Lucía Fernández', 'slug' => 'lucia-fernandez', 'profesion' => 'asesor', 'foto' => 'per-asesora',
                'matricula' => null, 'especialidades' => ['gestión de rodeos de cría', 'comercialización', 'registros y trazabilidad'], 'provincias' => ['22', '34', '18'],
                'bio' => 'Asesora ganadera. Indicadores de cría, armado de lotes para venta y registros para compradores.',
                'trabaja' => ['don-anselmo']],
        ] as $p) {
            $u = User::query()->firstOrCreate(['email' => $p['email']], ['nombre' => $p['nombre'], 'password' => self::PASSWORD, 'email_verificado_en' => now(), 'idioma' => 'es']);
            $sha = $this->foto($p['foto'], $u);
            $u->forceFill(['foto_sha256' => $sha])->save();
            DB::table('profesionales')->updateOrInsert(['usuario_id' => $u->id], [
                'slug' => $p['slug'], 'nombre_publico' => $p['nombre'], 'profesion' => $p['profesion'], 'matricula_declarada' => $p['matricula'],
                'especialidades' => '{'.implode(',', array_map(fn ($e) => '"'.$e.'"', $p['especialidades'])).'}', 'zonas' => json_encode(['provincias' => $p['provincias']]),
                'idiomas' => '{es}', 'bio' => $p['bio'], 'foto_sha256' => $sha, 'publico' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
            foreach ($ests($p['trabaja']) as $estId) {
                $dueno = DB::table('membresias')->where('establecimiento_id', $estId)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id');
                DB::table('profesional_establecimientos')->insertOrIgnore(['profesional_id' => $u->id, 'establecimiento_id' => $estId, 'autorizado_por' => $dueno,
                    'visible_en_perfil' => true, 'created_at' => now()]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sujetos
     * @param  array<string, mixed>  $payload
     */
    private function evento(string $tipo, array $sujetos, array $payload, string $fecha): void
    {
        $in = json_decode(json_encode(['tipo' => $tipo, 'sujetos' => $sujetos, 'payload' => $payload, 'ocurrido_en' => $fecha]), false);
        app(RegistrarEvento::class)->handle($this->est, $this->dueno, ArmarSobreWeb::alta($this->est, $this->dueno, $in), ['canal' => 'web', 'confirmar_advertencias' => true]);
    }

    /** @return list<array<string, mixed>> */
    private static function campos(): array
    {
        $t = fn (string $titulo, array $es, array $en, array $pt) => [
            'es' => ['titulo' => $titulo, 'descripcion' => $es[0], 'historia' => $es[1]],
            'en' => ['titulo' => $titulo, 'descripcion' => $en[0], 'historia' => $en[1]],
            'pt' => ['titulo' => $titulo, 'descripcion' => $pt[0], 'historia' => $pt[1]],
        ];
        $of = fn (string $es, string $en, string $pt, string $des, string $den, string $dpt) => [
            'es' => ['titulo' => $es, 'descripcion' => $des], 'en' => ['titulo' => $en, 'descripcion' => $den], 'pt' => ['titulo' => $pt, 'descripcion' => $dpt],
        ];

        return [
            [
                'slug' => 'el-timbo', 'nombre' => 'El Timbó', 'email' => 'eltimbo@tropilla.test', 'usuario' => 'Héctor Benítez', 'whatsapp' => '+5493786400101',
                'productor' => 'Benítez Hnos. S.H.', 'productor_tipo' => 'sociedad', 'provincia' => '18', 'departamento' => '18084', 'localidad' => 'Ituzaingó',
                'ubicacion' => ['lat' => '-27.58', 'lon' => '-56.69'], 'superficie_ha' => '1850', 'especies' => ['BOV'], 'actividades' => ['cria', 'recria', 'invernada'],
                'potrero' => ['nombre' => 'Potrero del Timbó', 'superficie_ha' => '420', 'ambiente' => 'loma', 'recurso_forrajero' => 'pastura_implantada', 'agua' => true, 'revolcadero' => false],
                'portada' => 'est-el-timbo',
                'textos' => $t('El Timbó — Braford de Ituzaingó',
                    ['Cría y recría Braford sobre pasturas subtropicales en Ituzaingó, Corrientes.', 'Campo de la familia Benítez desde 1985; Braford puro desde 2004, con registros de pesada en balanza.'],
                    ['Braford breeding and backgrounding on subtropical pastures in Ituzaingó, Corrientes.', 'Owned by the Benítez family since 1985; purebred Braford since 2004, with scale-weighing records.'],
                    ['Cria e recria Braford em pastagens subtropicais em Ituzaingó, Corrientes.', 'Fazenda da família Benítez desde 1985; Braford puro desde 2004, com registros de pesagem em balança.']),
                'lotes' => [
                    'terneros' => ['nombre' => 'Terneros Braford 2026', 'especie' => 'BOV', 'proposito' => 'recria', 'n' => 20, 'sexo' => 'M', 'categoria' => 'BOV_TERNERO', 'raza' => 'BOV_BRAFORD',
                        'castrado' => false, 'nacimiento' => '2026-02-01', 'peso' => [168, 196], 'prefijo' => 'T-'],
                    'novillos' => ['nombre' => 'Novillos Braford', 'especie' => 'BOV', 'proposito' => 'engorde', 'n' => 15, 'sexo' => 'M', 'categoria' => 'BOV_NOVILLO', 'raza' => 'BOV_BRAFORD',
                        'castrado' => true, 'denticion' => '4', 'nacimiento' => '2024-04-01', 'peso' => [395, 430], 'prefijo' => 'N-'],
                ],
                'ofertas' => [
                    ['lote' => 'terneros', 'tipo' => 'invernada', 'foto' => 'of-terneros-braford', 'textos' => $of('20 terneros Braford', '20 Braford calves', '20 bezerros Braford',
                        'Terneros de destete, 168 a 196 kg en balanza. Vacunados contra aftosa.', 'Weaned calves, 168–196 kg on the scale. Foot-and-mouth vaccinated.', 'Bezerros desmamados, 168 a 196 kg na balança. Vacinados contra aftosa.')],
                    ['lote' => 'novillos', 'tipo' => 'gordo', 'foto' => 'of-novillos-braford', 'textos' => $of('15 novillos Braford terminados', '15 finished Braford steers', '15 novilhos Braford terminados',
                        'Novillos de 4 dientes, 395 a 430 kg, terminados a pasto.', '4-tooth steers, 395–430 kg, grass-finished.', 'Novilhos de 4 dentes, 395 a 430 kg, terminados a pasto.')],
                ],
            ],
            [
                'slug' => 'los-caranday', 'nombre' => 'Los Caranday', 'email' => 'loscaranday@tropilla.test', 'usuario' => 'Rosa Villalba', 'whatsapp' => '+5493704400202',
                'productor' => 'Rosa Villalba', 'productor_tipo' => 'persona_humana', 'provincia' => '34', 'departamento' => '34056', 'localidad' => 'Pirané',
                'ubicacion' => ['lat' => '-25.73', 'lon' => '-59.11'], 'superficie_ha' => '1200', 'especies' => ['BUB'], 'actividades' => ['cria'],
                'potrero' => ['nombre' => 'Estero Grande', 'superficie_ha' => '380', 'ambiente' => 'banado', 'recurso_forrajero' => 'campo_natural', 'agua' => true, 'revolcadero' => true],
                'portada' => 'est-los-caranday',
                'textos' => $t('Los Caranday — Búfalos Murrah en Pirané',
                    ['Cría de búfalos Murrah en esteros y palmares de Pirané, Formosa.', 'Empezamos en 2012 con 30 búfalas; hoy hacemos cría con servicio natural y tacto ecográfico.'],
                    ['Murrah buffalo breeding in the marshes and palm groves of Pirané, Formosa.', 'We started in 2012 with 30 buffalo cows; today we breed with natural service and ultrasound checks.'],
                    ['Cria de búfalos Murrah em banhados e palmeirais de Pirané, Formosa.', 'Começamos em 2012 com 30 búfalas; hoje fazemos cria com monta natural e diagnóstico por ultrassom.']),
                'lotes' => [
                    'bufalas' => ['nombre' => 'Búfalas Murrah preñadas', 'especie' => 'BUB', 'proposito' => 'cria', 'n' => 12, 'sexo' => 'H', 'categoria' => 'BUB_BUFALA', 'raza' => 'BUB_MURRAH',
                        'denticion' => '6_mas', 'nacimiento' => '2020-08-01', 'peso' => [540, 610], 'prefijo' => 'C-', 'tacto' => true],
                ],
                'ofertas' => [
                    ['lote' => 'bufalas', 'tipo' => 'cria', 'foto' => 'of-bufalas-murrah-prenadas', 'textos' => $of('12 búfalas Murrah preñadas', '12 pregnant Murrah buffalo cows', '12 búfalas Murrah prenhes',
                        'Preñez confirmada por ecografía (28/09/2026). 540 a 610 kg.', 'Pregnancy confirmed by ultrasound (2026-09-28). 540–610 kg.', 'Prenhez confirmada por ultrassom (28/09/2026). 540 a 610 kg.')],
                ],
            ],
            [
                'slug' => 'don-anselmo', 'nombre' => 'Don Anselmo', 'email' => 'donanselmo@tropilla.test', 'usuario' => 'Anselmo Ruiz Díaz', 'whatsapp' => '+5493624400303',
                'productor' => 'Ruiz Díaz Ganadera S.A.', 'productor_tipo' => 'sociedad', 'provincia' => '22', 'departamento' => '22084', 'localidad' => 'General José de San Martín',
                'ubicacion' => ['lat' => '-26.54', 'lon' => '-59.34'], 'superficie_ha' => '3100', 'especies' => ['BOV'], 'actividades' => ['cria', 'recria'],
                'potrero' => ['nombre' => 'Abra del Quebracho', 'superficie_ha' => '650', 'ambiente' => 'loma', 'recurso_forrajero' => 'pastura_implantada', 'agua' => true, 'revolcadero' => false],
                'portada' => 'est-don-anselmo',
                'textos' => $t('Don Anselmo — Brangus del Chaco',
                    ['Cría Brangus en el monte chaqueño, con pasturas de gatton panic y registros de cada pesada.', 'Tres generaciones de la familia Ruiz Díaz; Brangus colorado y negro desde 1998.'],
                    ['Brangus breeding in the Chaco woodland, on gatton panic pastures with every weighing recorded.', 'Three generations of the Ruiz Díaz family; red and black Brangus since 1998.'],
                    ['Cria Brangus no mato chaquenho, com pastagens de gatton panic e registro de cada pesagem.', 'Três gerações da família Ruiz Díaz; Brangus vermelho e preto desde 1998.']),
                'lotes' => [
                    'vaquillonas' => ['nombre' => 'Vaquillonas Brangus', 'especie' => 'BOV', 'proposito' => 'recria', 'n' => 18, 'sexo' => 'H', 'categoria' => 'BOV_VAQUILLONA', 'raza' => 'BOV_BRANGUS',
                        'nacimiento' => '2024-09-01', 'peso' => [300, 335], 'prefijo' => 'V-'],
                ],
                'ofertas' => [
                    ['lote' => 'vaquillonas', 'tipo' => 'cria', 'foto' => 'of-vaquillonas-brangus', 'textos' => $of('18 vaquillonas Brangus para entorar', '18 Brangus heifers ready for breeding', '18 novilhas Brangus para cobertura',
                        'Vaquillonas de 2 años, 300 a 335 kg, listas para el servicio de primavera.', '2-year-old heifers, 300–335 kg, ready for the spring breeding season.', 'Novilhas de 2 anos, 300 a 335 kg, prontas para a estação de monta de primavera.')],
                ],
            ],
            [
                'slug' => 'la-paloma', 'nombre' => 'La Paloma', 'email' => 'lapaloma@tropilla.test', 'usuario' => 'Inés Kowalczuk', 'whatsapp' => '+5493758400404',
                'productor' => 'Inés Kowalczuk', 'productor_tipo' => 'persona_humana', 'provincia' => '54', 'departamento' => '54007', 'localidad' => 'Apóstoles',
                'ubicacion' => ['lat' => '-27.91', 'lon' => '-55.75'], 'superficie_ha' => '640', 'especies' => ['BOV'], 'actividades' => ['cria'],
                'potrero' => ['nombre' => 'Loma Colorada', 'superficie_ha' => '210', 'ambiente' => 'loma', 'recurso_forrajero' => 'pastura_implantada', 'agua' => true, 'revolcadero' => false],
                'portada' => 'est-la-paloma',
                'textos' => $t('La Paloma — Hereford de Apóstoles',
                    ['Cría Hereford en las lomas coloradas de Apóstoles, Misiones, con yerbales y pasturas.', 'Chacra familiar de inmigrantes polacos; el rodeo Hereford empezó en 1972.'],
                    ['Hereford breeding on the red hills of Apóstoles, Misiones, among yerba mate fields and pastures.', 'A family farm founded by Polish immigrants; the Hereford herd started in 1972.'],
                    ['Cria Hereford nas colinas vermelhas de Apóstoles, Misiones, entre ervais e pastagens.', 'Chácara familiar de imigrantes poloneses; o rebanho Hereford começou em 1972.']),
                'lotes' => [
                    'vacas' => ['nombre' => 'Vacas Hereford', 'especie' => 'BOV', 'proposito' => 'cria', 'n' => 14, 'sexo' => 'H', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_HEREFORD',
                        'denticion' => '6_mas', 'nacimiento' => '2020-09-01', 'peso' => [430, 485], 'prefijo' => 'H-', 'tacto' => true],
                ],
                'ofertas' => [
                    ['lote' => 'vacas', 'tipo' => 'cria', 'foto' => 'of-vacas-hereford', 'textos' => $of('14 vacas Hereford preñadas', '14 pregnant Hereford cows', '14 vacas Hereford prenhes',
                        'Vacas adultas con preñez confirmada por ecografía, 430 a 485 kg.', 'Mature cows with ultrasound-confirmed pregnancy, 430–485 kg.', 'Vacas adultas com prenhez confirmada por ultrassom, 430 a 485 kg.')],
                ],
            ],
            [
                'slug' => 'santa-rita', 'nombre' => 'Santa Rita', 'email' => 'santarita@tropilla.test', 'usuario' => 'Julio Ojeda', 'whatsapp' => '+5493773400505',
                'productor' => 'Estancia Santa Rita S.R.L.', 'productor_tipo' => 'sociedad', 'provincia' => '18', 'departamento' => '18105', 'localidad' => 'Mercedes',
                'ubicacion' => ['lat' => '-29.18', 'lon' => '-58.08'], 'superficie_ha' => '2700', 'especies' => ['BUB'], 'actividades' => ['cria', 'recria', 'cabana'],
                'potrero' => ['nombre' => 'Bañado del Payubre', 'superficie_ha' => '700', 'ambiente' => 'banado', 'recurso_forrajero' => 'campo_natural', 'agua' => true, 'revolcadero' => true],
                'portada' => 'est-santa-rita',
                'textos' => $t('Santa Rita — Búfalos Mediterráneos y Jafarabadi',
                    ['Cabaña y cría de búfalos Mediterráneos y Jafarabadi en los esteros de Mercedes, Corrientes.', 'Pioneros del búfalo en Mercedes desde 1995; venta de reproductores con genealogía registrada.'],
                    ['Mediterranean and Jafarabadi buffalo stud and breeding in the marshes of Mercedes, Corrientes.', 'Buffalo pioneers in Mercedes since 1995; breeding stock sold with recorded pedigree.'],
                    ['Cabanha e cria de búfalos Mediterrâneos e Jafarabadi nos banhados de Mercedes, Corrientes.', 'Pioneiros do búfalo em Mercedes desde 1995; venda de reprodutores com genealogia registrada.']),
                'lotes' => [
                    'toros' => ['nombre' => 'Toros Jafarabadi', 'especie' => 'BUB', 'proposito' => 'reproductores', 'n' => 4, 'sexo' => 'M', 'categoria' => 'BUB_BUFALO_TORO', 'raza' => 'BUB_JAFARABADI',
                        'castrado' => false, 'denticion' => '6_mas', 'nacimiento' => '2021-07-01', 'peso' => [680, 740], 'prefijo' => 'J-'],
                    'bucerros' => ['nombre' => 'Bucerros castrados', 'especie' => 'BUB', 'proposito' => 'recria', 'n' => 16, 'sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'raza' => 'BUB_MEDITERRANEA',
                        'castrado' => true, 'nacimiento' => '2025-12-15', 'peso' => [230, 250], 'prefijo' => 'BC-'],
                    'bufalas' => ['nombre' => 'Búfalas Mediterráneas', 'especie' => 'BUB', 'proposito' => 'cria', 'n' => 8, 'sexo' => 'H', 'categoria' => 'BUB_BUFALA', 'raza' => 'BUB_MEDITERRANEA',
                        'denticion' => '6_mas', 'nacimiento' => '2019-10-01', 'peso' => [520, 580], 'prefijo' => 'M-'],
                ],
                'ofertas' => [
                    ['lote' => 'toros', 'tipo' => 'reproductores', 'foto' => 'of-toros-jafarabadi', 'textos' => $of('4 toros búfalos Jafarabadi', '4 Jafarabadi buffalo bulls', '4 touros búfalos Jafarabadi',
                        'Toros adultos de cabaña, 680 a 740 kg.', 'Mature stud bulls, 680–740 kg.', 'Touros adultos de cabanha, 680 a 740 kg.')],
                    ['lote' => 'bucerros', 'tipo' => 'invernada', 'foto' => 'of-bucerros-castrados', 'textos' => $of('16 bucerros castrados', '16 castrated buffalo calves', '16 bezerros bubalinos castrados',
                        'Bucerros Mediterráneos castrados de 230 a 250 kg, listos para recría.', 'Castrated Mediterranean buffalo calves, 230–250 kg, ready for backgrounding.', 'Bezerros Mediterrâneos castrados de 230 a 250 kg, prontos para recria.')],
                ],
                'foto_animal' => ['toros', 'an-toro-bufalo'],
            ],
        ];
    }
}
