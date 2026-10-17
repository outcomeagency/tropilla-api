<?php

namespace Database\Seeders;

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use stdClass;

/** Datos regionales sintéticos para desarrollo y demo. Solo agrega registros bajo cuentas demo conocidas. */
class DemoRegionalSeeder extends Seeder
{
    private const FICHAS = [
        'la-esperanza' => [
            'sanidad_renspa' => ['renspa_vencimiento' => '2027-06-30', 'senasa_vencimiento' => '2027-03-31', 'plan_sanitario' => 'Aftosa, brucelosis, rabia y control de garrapata'],
            'sanidad' => ['manejo' => 'Plan sanitario declarado con asesoramiento veterinario', 'vacunaciones_actualizadas' => 'Campañas vigentes; registros por lote', 'fecha' => '2026-10-01'],
            'potreros_aguadas' => ['potreros' => '18', 'aguadas' => '24', 'rotacion' => 'Rotación estacional entre lomas y esteros', 'fuentes_agua' => 'Arroyos, tajamares y perforaciones'],
            'suelo_pasturas' => ['suelos' => 'Lomas arenosas y bajos hidromórficos', 'pasturas' => 'Campo natural, Setaria y Brachiaria', 'ambientes' => 'Loma, media loma y estero'],
            'capacidad_carga' => ['carga_ug_ha' => '0.72', 'periodo' => 'Promedio 2024–2026', 'criterio' => 'Equivalente vaca sobre superficie ganadera'],
            'infraestructura' => ['manga' => 'Manga curva con cepo', 'balanza' => 'Balanza electrónica', 'alambrados' => 'Perimetrales y eléctricos internos', 'energia' => 'Solar en puestos y red en casco'],
            'personal' => ['equipo' => '7'],
            'marcas_senales' => ['marca' => 'DR entrelazadas', 'senal' => 'Muesca oreja derecha, declarada'],
            'clima' => ['precipitacion_media_mm' => '1350', 'temperatura_media_c' => '21.4', 'fuente' => 'INTA EEA Corrientes', 'fecha' => '2026-10-01'],
            'logistica' => ['distancia_ruta_km' => '18', 'distancia_puerto_km' => '185', 'accesos' => 'RP 5; accesos bajos con lluvia intensa', 'carga' => 'Embarcadero sobre camino vecinal'],
            'certificaciones' => ['nombre' => 'Programa de Buenas Prácticas Ganaderas (declarado)', 'emisor' => 'INTA / equipo técnico', 'vence' => '2027-12-31', 'alcance' => 'Cría bovina y bubalina'],
        ],
        'el-timbo' => ['potreros_aguadas' => ['potreros' => '12', 'aguadas' => '10', 'rotacion' => 'Pastoreo rotativo', 'fuentes_agua' => 'Arroyos y perforación'], 'suelo_pasturas' => ['suelos' => 'Lomas y albardones', 'pasturas' => 'Setaria y pastizal natural'], 'capacidad_carga' => ['carga_ug_ha' => '0.84', 'periodo' => 'Promedio 2025–2026'], 'infraestructura' => ['manga' => 'Manga con cepo', 'balanza' => 'Balanza electrónica'], 'personal' => ['equipo' => '4'], 'marcas_senales' => ['marca' => 'HB', 'senal' => 'Muesca izquierda, declarada'], 'clima' => ['precipitacion_media_mm' => '1550', 'fuente' => 'INTA EEA Corrientes', 'fecha' => '2026-09-30'], 'logistica' => ['distancia_ruta_km' => '7', 'accesos' => 'RP 12, transitable todo el año'], 'certificaciones' => ['nombre' => 'Registros genealógicos Braford (declarado)', 'emisor' => 'Asociación de Criadores']],
        'don-anselmo' => ['potreros_aguadas' => ['potreros' => '9', 'aguadas' => '8', 'fuentes_agua' => 'Represas y perforaciones'], 'suelo_pasturas' => ['suelos' => 'Monte chaqueño y cañadas', 'pasturas' => 'Gatton panic y campo natural'], 'capacidad_carga' => ['carga_ug_ha' => '0.48', 'periodo' => 'Promedio 2024–2026'], 'infraestructura' => ['manga' => 'Manga de madera dura', 'alambrados' => 'Perimetrales tradicionales'], 'personal' => ['equipo' => '5'], 'marcas_senales' => ['marca' => 'DA', 'senal' => 'Corte oreja izquierda, declarada'], 'clima' => ['precipitacion_media_mm' => '1050', 'fuente' => 'Estación INTA Sáenz Peña', 'fecha' => '2026-09-30'], 'logistica' => ['distancia_ruta_km' => '22', 'accesos' => 'Camino consolidado; consultar en época de lluvias']],
        'los-caranday' => ['potreros_aguadas' => ['potreros' => '14', 'aguadas' => '19', 'rotacion' => 'Descanso de esteros por estación', 'fuentes_agua' => 'Esteros y tajamares'], 'suelo_pasturas' => ['suelos' => 'Bajos inundables y palmares', 'pasturas' => 'Canutillo, paja boba y campo natural'], 'capacidad_carga' => ['carga_ug_ha' => '0.62', 'periodo' => 'Promedio 2024–2026'], 'infraestructura' => ['manga' => 'Manga para búfalos', 'revolcaderos' => 'Acceso a estero'], 'personal' => ['equipo' => '3'], 'marcas_senales' => ['marca' => 'RV', 'senal' => 'Muesca oreja derecha, declarada'], 'clima' => ['precipitacion_media_mm' => '1250', 'fuente' => 'Estación INTA El Colorado', 'fecha' => '2026-09-30'], 'logistica' => ['distancia_ruta_km' => '11', 'accesos' => 'RP 3; revisar pasos bajos con crecida'], 'certificaciones' => ['nombre' => 'Protocolo de manejo bubalino (declarado)', 'emisor' => 'Equipo técnico local']],
        'la-paloma' => ['potreros_aguadas' => ['potreros' => '8', 'aguadas' => '7', 'rotacion' => 'Pastoreo en franjas', 'fuentes_agua' => 'Vertientes y perforaciones'], 'suelo_pasturas' => ['suelos' => 'Lomas rojas lateríticas', 'pasturas' => 'Brachiaria y pastizal de loma'], 'capacidad_carga' => ['carga_ug_ha' => '0.91', 'periodo' => 'Promedio 2025–2026'], 'infraestructura' => ['manga' => 'Manga con cepo y cargador', 'balanza' => 'Balanza mecánica'], 'personal' => ['equipo' => '3'], 'marcas_senales' => ['marca' => 'LV', 'senal' => 'Muesca combinada, declarada'], 'clima' => ['precipitacion_media_mm' => '1700', 'fuente' => 'Estación INTA Cerro Azul', 'fecha' => '2026-09-30'], 'logistica' => ['distancia_ruta_km' => '5', 'distancia_puerto_km' => '75', 'accesos' => 'RN 14 y caminos de tierra'], 'certificaciones' => ['nombre' => 'Protocolo de producción pastoril (declarado)', 'emisor' => 'Cooperativa regional']],
        'santa-rita' => ['potreros_aguadas' => ['potreros' => '16', 'aguadas' => '21', 'rotacion' => 'Lotes por ambiente', 'fuentes_agua' => 'Esteros, arroyos y tajamares'], 'suelo_pasturas' => ['suelos' => 'Esteros y lomadas arenosas', 'pasturas' => 'Pasto clavel y campo natural'], 'capacidad_carga' => ['carga_ug_ha' => '0.68', 'periodo' => 'Promedio 2024–2026'], 'infraestructura' => ['manga' => 'Manga doble apta para búfalos', 'balanza' => 'Balanza electrónica'], 'personal' => ['equipo' => '6'], 'marcas_senales' => ['marca' => 'SR', 'senal' => 'Muesca oreja izquierda, declarada'], 'clima' => ['precipitacion_media_mm' => '1400', 'fuente' => 'INTA EEA Mercedes', 'fecha' => '2026-09-30'], 'logistica' => ['distancia_ruta_km' => '14', 'accesos' => 'RP 40; acceso por terraplén'], 'certificaciones' => ['nombre' => 'Plan de manejo de humedales (declarado)', 'emisor' => 'Equipo técnico']],
    ];

    public function run(): void
    {
        // Estas dos fuentes son sintéticas y existentes; sus inserciones son idempotentes y nunca consultan prueba@tropilla.ar.
        $this->call([DemoLaEsperanzaSeeder::class, DemoDirectorioSeeder::class]);
        $this->completarFichas();
        $this->sembrarGenealogia();
        $this->sembrarOrganizaciones();
        $this->sembrarDocumentos();
        $this->sembrarCampos();
    }

    private function completarFichas(): void
    {
        foreach (self::FICHAS as $slug => $bloques) {
            $defaults = [
                'sanidad_renspa' => ['renspa_vencimiento' => '2027-06-30', 'senasa_vencimiento' => '2027-03-31'],
                'sanidad' => ['manejo' => 'Plan sanitario declarado; validar con veterinario local', 'vacunaciones_actualizadas' => 'Campañas vigentes; registros por lote', 'fecha' => '2026-10-01'],
                'potreros_aguadas' => ['potreros' => '8', 'aguadas' => '9', 'rotacion' => 'Rotación por ambiente y estación', 'fuentes_agua' => 'Tajamares y perforaciones'],
                'suelo_pasturas' => ['suelos' => 'Lomas y bajos característicos del NEA', 'pasturas' => 'Campo natural y pasturas subtropicales'],
                'capacidad_carga' => ['carga_ug_ha' => '0.65', 'periodo' => 'Promedio 2024–2026', 'criterio' => 'Equivalente vaca por superficie ganadera'],
                'infraestructura' => ['manga' => 'Manga y corrales', 'balanza' => 'Balanza disponible', 'alambrados' => 'Perimetrales e internos', 'energia' => 'Red y apoyo solar'],
                'personal' => ['equipo' => '3'],
                'marcas_senales' => ['marca' => 'Marca declarada por el productor', 'senal' => 'Señal declarada por el productor'],
                'clima' => ['precipitacion_media_mm' => '1300', 'fuente' => 'Estación meteorológica INTA de referencia', 'fecha' => '2026-09-30'],
                'logistica' => ['distancia_ruta_km' => '15', 'accesos' => 'Camino rural; consultar transitabilidad luego de lluvias'],
                'certificaciones' => ['nombre' => 'Programa de buenas prácticas (declarado)', 'emisor' => 'Equipo técnico regional', 'vence' => '2027-12-31'],
            ];
            $bloques = array_replace_recursive($defaults, $bloques);
            $estId = DB::table('perfiles_publicos')->where('slug', $slug)->value('establecimiento_id');
            if (! $estId) {
                continue;
            }
            DB::transaction(function () use ($estId, $slug, $bloques) {
                $est = Establecimiento::query()->lockForUpdate()->findOrFail($estId);
                $actual = $est->ficha_ampliada ?? [];
                $visibilidad = $est->visibilidad_ficha ?? [];
                $siguiente = array_replace_recursive($bloques, $actual);
                $visibles = array_replace($slug === 'la-esperanza' ? ['potreros_aguadas' => true, 'sanidad' => true, 'personal' => true, 'marcas_senales' => true, 'capacidad_carga' => true, 'clima' => true, 'certificaciones' => true] : ['potreros_aguadas' => true, 'sanidad' => true, 'personal' => true, 'marcas_senales' => true, 'capacidad_carga' => true, 'clima' => true], $visibilidad);
                if ($siguiente === $actual && $visibles === $visibilidad) {
                    return;
                }
                $est->ficha_ampliada = $siguiente;
                $est->visibilidad_ficha = $visibles;
                $est->row_version++;
                $est->save();
                app(RegistrarCambio::class)->handle($est->id, 'establecimiento', $est->id, cadena: Cadena::bloquearDeEstablecimiento($est->id));
            });
        }

        $id = DB::table('perfiles_publicos')->where('slug', 'la-esperanza')->value('establecimiento_id');
        if ($id && ! DB::table('establecimientos')->where('id', $id)->whereNotNull('lindero')->exists()) {
            DB::statement("UPDATE establecimientos SET lindero = ST_Multi(ST_GeomFromText('POLYGON((-57.64 -27.76,-57.60 -27.76,-57.60 -27.74,-57.64 -27.76))',4326)) WHERE id = ? AND lindero IS NULL", [$id]);
        }
    }

    private function sembrarGenealogia(): void
    {
        $est = Establecimiento::query()->where('nombre', 'La Esperanza')->first();
        $dueno = $est ? User::query()->whereIn('id', DB::table('membresias')->where('establecimiento_id', $est->id)->where('rol', 'dueno')->where('estado', 'activa')->select('usuario_id'))->first() : null;
        if (! $est || ! $dueno) {
            return;
        }

        $niveles = [];
        for ($gen = 1; $gen <= 5; $gen++) {
            $cantidad = 2 ** (6 - $gen);
            $niveles[$gen] = [];
            for ($i = 0; $i < $cantidad; $i++) {
                $rp = "TPA76-BOV-G{$gen}-".str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $fila = DB::table('reproductores_externos')->where('establecimiento_id', $est->id)->where('rp', $rp)->first();
                if (! $fila) {
                    $id = (string) Str::uuid7();
                    $madre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2) % count($niveles[$gen - 1])];
                    $padre = $gen === 1 ? null : $niveles[$gen - 1][($i * 2 + 1) % count($niveles[$gen - 1])];
                    // Los dos ramales del caso demo comparten el abuelo G2-01 (consanguinidad intencional de muestra).
                    if ($gen === 3 && $i === 2) {
                        $padre = $niveles[$gen - 1][0];
                    }
                    $datos = ['nombre' => "Braford Don Ramón G{$gen}-".($i + 1), 'rp' => $rp, 'registro_genealogico' => "BRAF-NEA-{$gen}-".str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'raza' => 'Braford', 'especie_codigo' => 'BOV', 'centro' => 'Cabaña Santa Rosa (declarado)', 'madre_externa_id' => $madre, 'padre_externa_id' => $padre];
                    DB::transaction(function () use ($est, $dueno, $id, $datos) {
                        $cadena = Cadena::bloquearDeEstablecimiento($est->id);
                        DB::table('reproductores_externos')->insert(['id' => $id, 'establecimiento_id' => $est->id, ...$datos, 'row_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                        DB::table('reproductor_externo_versiones')->insert(['reproductor_id' => $id, 'establecimiento_id' => $est->id, 'version' => 1, 'accion' => 'alta', 'datos' => json_encode($datos), 'autor_id' => $dueno->id, 'created_at' => now()]);
                        app(RegistrarCambio::class)->handle($est->id, 'reproductor_externo', $id, cadena: $cadena);
                    });
                    $niveles[$gen][] = $id;
                } else {
                    $niveles[$gen][] = $fila->id;
                }
            }
        }

        $namespace = Uuid::fromString('9df16ac2-76e0-4c3e-b451-4d98403fae42');
        $animalId = Uuid::uuid5($namespace, 'tpa76-vaquillona-inseminada')->toString();
        if (! DB::table('animales')->where('id', $animalId)->exists()) {
            $eventoId = Uuid::uuid5($namespace, 'tpa76-alta-vaquillona')->toString();
            $alta = json_decode(json_encode(['id' => $eventoId, 'tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$animalId]], 'payload' => ['motivo' => 'inventario_inicial', 'animales' => [[
                'animal_id' => $animalId, 'especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VAQUILLONA', 'raza' => 'BOV_BRAFORD', 'fecha_nacimiento' => '2023-08-15', 'nacimiento_estimado' => true,
                'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'TPA76-IA-001']], 'madre_externa_id' => $niveles[5][0], 'padre_externo_id' => $niveles[5][1],
            ]]], 'ocurrido_en' => '2025-01-15']), false);
            app(RegistrarEvento::class)->handle($est, $dueno, ArmarSobreWeb::alta($est, $dueno, $alta), ['canal' => 'web', 'confirmar_advertencias' => true]);
        }
        $inseminacionId = Uuid::uuid5($namespace, 'tpa76-iatf-pajuela')->toString();
        if (! DB::table('eventos')->where('id', $inseminacionId)->exists()) {
            $ia = json_decode(json_encode(['id' => $inseminacionId, 'tipo' => 'reproduccion.inseminacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$animalId]], 'payload' => ['tipo' => 'iatf', 'semen' => [
                'reproductor_externo_id' => $niveles[5][0], 'toro_nombre' => 'Braford Don Ramón G5-1', 'raza' => 'BOV_BRAFORD', 'centro' => 'Centro de IA Corrientes', 'partida' => 'PAJ-NEA-2026-014', 'lote_pajuelas' => 'PAJ-NEA-2026-014', 'cantidad_dosis' => 1,
            ], 'inseminador' => 'Vet. Juan Pérez (demo)'], 'ocurrido_en' => '2026-09-21']), false);
            app(RegistrarEvento::class)->handle($est, $dueno, ArmarSobreWeb::alta($est, $dueno, $ia), ['canal' => 'web', 'confirmar_advertencias' => true]);
        }
    }

    private function sembrarOrganizaciones(): void
    {
        $grupos = [
            ['slug' => 'demo-asociacion-criadores-nea', 'nombre' => 'Asociación de Criadores del NEA (demo)', 'tipo' => 'asociacion', 'provincias' => ['18', '22', '34', '54']],
            ['slug' => 'demo-programa-bubalino-corrientes', 'nombre' => 'Programa Bubalino de Corrientes (demo)', 'tipo' => 'programa_provincial', 'provincias' => ['18']],
            ['slug' => 'demo-cooperativa-pasturas-misiones', 'nombre' => 'Cooperativa de Pasturas Misioneras (demo)', 'tipo' => 'cooperativa', 'provincias' => ['54']],
        ];
        foreach ($grupos as $grupo) {
            DB::table('organizaciones')->insertOrIgnore(['id' => (string) Str::uuid7(), 'slug' => $grupo['slug'], 'nombre' => $grupo['nombre'], 'tipo' => $grupo['tipo'], 'ambito' => json_encode(['provincias' => $grupo['provincias']]), 'especies' => '{BOV,BUB}', 'descripcion' => 'Registro sintético para explorar adhesiones, consentimiento y trabajo por organización.', 'created_at' => now(), 'updated_at' => now()]);
            $orgId = DB::table('organizaciones')->where('slug', $grupo['slug'])->value('id');
            foreach (DB::table('perfiles_publicos')->whereIn('slug', array_keys(self::FICHAS))->pluck('establecimiento_id') as $estId) {
                $productorId = DB::table('establecimientos')->where('id', $estId)->value('productor_id');
                $dueno = DB::table('membresias')->where('establecimiento_id', $estId)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id');
                if (! $productorId || ! $dueno) {
                    continue;
                }
                DB::table('organizacion_miembros')->insertOrIgnore(['id' => (string) Str::uuid7(), 'organizacion_id' => $orgId, 'usuario_id' => $dueno, 'rol' => 'admin', 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('adhesiones')->insertOrIgnore(['id' => (string) Str::uuid7(), 'productor_id' => $productorId, 'organizacion_id' => $orgId, 'estado' => 'activa', 'numero_socio' => 'NEA-'.substr(str_replace('-', '', $estId), 0, 6), 'version_vigente' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function sembrarDocumentos(): void
    {
        $contenido = "%PDF-1.4\nTROPILLA DEMO — constancia sanitaria declarada\n%%EOF\n";
        foreach (DB::table('perfiles_publicos')->whereIn('slug', array_keys(self::FICHAS))->get(['establecimiento_id', 'slug']) as $perfil) {
            if (DB::table('documentos')->where('establecimiento_id', $perfil->establecimiento_id)->where('titulo', 'Constancia sanitaria de ejemplo (declarada)')->exists()) {
                continue;
            }
            $est = Establecimiento::query()->find($perfil->establecimiento_id);
            $duenoId = DB::table('membresias')->where('establecimiento_id', $perfil->establecimiento_id)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id');
            $dueno = $duenoId ? User::query()->find($duenoId) : null;
            if (! $est || ! $dueno) {
                continue;
            }
            $sha = app(GuardarArchivo::class)->handle($contenido, 'application/pdf', $dueno);
            $namespace = Uuid::fromString('3bf86391-8165-4a03-a03a-2779d605f0bf');
            $uuid = fn (string $tag) => Uuid::uuid5($namespace, $perfil->slug.'-'.$tag)->toString();
            $sobre = new stdClass;
            $sobre->esquema = 'tropilla.documento_version/1';
            $sobre->id = $uuid('version');
            $sobre->documento_id = $uuid('documento');
            $sobre->establecimiento_id = $est->id;
            $sobre->version = 1;
            $sobre->accion = 'alta';
            $sobre->tipo = 'vacunacion_aftosa';
            $sobre->privacidad = 'sanitario';
            $sobre->titulo = 'Constancia sanitaria de ejemplo (declarada)';
            $sobre->emisor = (object) ['nombre' => 'Ente sanitario local (demo)', 'identificacion' => null];
            $sobre->emitido_en = '2026-05-20';
            $sobre->vence_en = '2027-05-20';
            $sobre->vinculos = [(object) ['tipo' => 'establecimiento', 'id' => $est->id]];
            $sobre->archivos = [(object) ['sha256' => $sha, 'bytes' => strlen($contenido), 'mime' => 'application/pdf']];
            $sobre->creado_en = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
            $sobre->autor_id = $dueno->id;
            app(RegistrarDocumentoVersion::class)->handle($est, $dueno, $sobre, ['canal' => 'web', 'rol' => Rol::Dueno]);
        }
    }

    /** Mercado de campos (TPA-74): avisos sintéticos del piloto NEA, una consulta en curso y un aviso con contacto verificado. */
    private function sembrarCampos(): void
    {
        $ns = Uuid::fromString('8d0c7b2e-5f4a-4c1e-9b7d-2a6f0e3c1d74');
        $hoy = now()->startOfMonth();
        $avisos = [
            ['santa-rita', 'pastaje-esteros', 'Pastaje en esteros de Mercedes, apto búfalos', 'pastaje', ['BUB', 'BOV'], [], 250, '900', 0, 8, 'por_cabeza_mes', '9800', ['aguada_natural', 'tajamar'], 'bueno', ['manga', 'corrales', 'embarcadero'], 'contacto_verificado',
                'Esteros con canutillo y lomadas de descanso. Recibimos recría y engorde; sanidad al día exigida al ingreso.'],
            ['el-timbo', 'recria-ituzaingo', 'Recría en pasturas de Ituzaingó', 'pastaje', ['BOV'], ['BOV_TERNERO', 'BOV_NOVILLITO'], 120, '420', 1, 7, 'kg_carne_ha_anio', '60', ['perforacion', 'bebederos'], 'bueno', ['manga', 'balanza', 'corrales'], 'declarado',
                'Setaria y Brachiaria con suplementación invernal opcional (a cargo del dueño de la hacienda).'],
            ['don-anselmo', 'arriendo-chaco', 'Arriendo de monte y pasturas en San Martín (Chaco)', 'arrendamiento', ['BOV'], [], 300, '1200', 2, null, 'por_ha_anio', '45000', ['tajamar', 'perforacion'], 'regular', ['manga', 'corrales', 'casa_puesto'], 'declarado',
                'Gatton panic implantado y campo natural. Plazo mínimo tres años; alambrado perimetral a reparar en un tramo.'],
            ['los-caranday', 'capitalizacion-pirane', 'Capitalización de búfalas en Pirané', 'capitalizacion', ['BUB'], ['BUB_BUFALA', 'BUB_BUBILLA'], 80, '380', 0, 24, 'porcentaje_ganancia', '50', ['aguada_natural', 'rio_arroyo'], 'bueno', ['manga', 'corrales'], 'declarado',
                'Capitalización a medias de los terneros logrados. Manga apta búfalos y acceso permanente a estero.'],
            ['la-paloma', 'invernada-apostoles', 'Invernada corta en lomas de Apóstoles', 'pastaje', ['BOV'], ['BOV_NOVILLO', 'BOV_VAQUILLONA'], 60, '150', 1, 5, 'a_convenir', null, ['perforacion', 'bebederos'], 'bueno', ['manga', 'balanza'], 'declarado',
                'Brachiaria en franjas con agua en cada parcela. Ideal para terminar novillos antes del verano.'],
            ['la-esperanza', 'capitalizacion-la-esperanza', 'Capitalización de vaquillonas en La Esperanza', 'capitalizacion', ['BOV', 'BUB'], [], 100, '600', 0, 18, 'porcentaje_ganancia', '45', ['tajamar', 'rio_arroyo', 'perforacion'], 'bueno', ['manga', 'balanza', 'corrales', 'embarcadero'], 'declarado',
                'Recibimos vaquillonas para entorar con nuestros toros; sanidad y registros en TROPILLA.'],
        ];
        foreach ($avisos as [$slug, $tag, $titulo, $modalidad, $especies, $cats, $capacidad, $sup, $desdeMes, $meses, $unidad, $valor, $agua, $alambrado, $inst, $verif, $desc]) {
            $est = DB::table('perfiles_publicos as p')->join('establecimientos as e', 'e.id', '=', 'p.establecimiento_id')->where('p.slug', $slug)
                ->first(['e.id', 'e.provincia_codigo', 'e.departamento_codigo', 'e.localidad']);
            $autor = $est ? DB::table('membresias')->where('establecimiento_id', $est->id)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id') : null;
            if (! $est || ! $autor || ! in_array($est->provincia_codigo, ['18', '22', '34', '54'], true)) {
                continue;
            }
            $desde = $hoy->copy()->addMonths($desdeMes);
            DB::table('campo_avisos')->insertOrIgnore([
                'id' => Uuid::uuid5($ns, "{$slug}-{$tag}")->toString(), 'establecimiento_id' => $est->id, 'autor_id' => $autor, 'titulo' => $titulo, 'descripcion' => $desc,
                'modalidad' => $modalidad, 'especies' => '{'.implode(',', $especies).'}', 'categorias' => '{'.implode(',', $cats).'}', 'capacidad_cabezas' => $capacidad, 'superficie_ha' => $sup,
                'temporada_desde' => $desde->toDateString(), 'temporada_hasta' => $meses ? $desde->copy()->addMonths($meses)->toDateString() : null, 'plazo_minimo_meses' => $modalidad === 'arrendamiento' ? 36 : null,
                'precio_unidad' => $unidad, 'precio_valor' => $valor, 'agua' => '{'.implode(',', $agua).'}', 'alambrado' => $alambrado, 'instalaciones' => '{'.implode(',', $inst).'}',
                'accesos' => 'Consultar transitabilidad después de lluvias.', 'provincia_codigo' => $est->provincia_codigo, 'departamento_codigo' => $est->departamento_codigo,
                'localidad' => $est->localidad, 'mostrar_localidad' => true, 'estado' => 'publicado', 'verificacion' => $verif,
                'verificacion_nota' => $verif === 'declarado' ? null : 'Llamada de verificación al anunciante (demo).', 'verificado_en' => $verif === 'declarado' ? null : now(),
                'publicado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Consulta de Los Caranday por el aviso de La Esperanza, ya respondida.
        $aviso = Uuid::uuid5($ns, 'la-esperanza-capitalizacion-la-esperanza')->toString();
        $interesado = DB::table('perfiles_publicos')->where('slug', 'los-caranday')->value('establecimiento_id');
        $usuario = $interesado ? DB::table('membresias')->where('establecimiento_id', $interesado)->where('rol', 'dueno')->where('estado', 'activa')->value('usuario_id') : null;
        $anunciante = DB::table('campo_avisos')->where('id', $aviso)->value('autor_id');
        if ($usuario && $anunciante) {
            $contacto = fn (string $id) => json_encode((array) DB::table('usuarios')->where('id', $id)->first(['nombre', 'email', 'telefono']), JSON_UNESCAPED_UNICODE);
            DB::table('campo_consultas')->insertOrIgnore([
                'id' => Uuid::uuid5($ns, 'consulta-los-caranday')->toString(), 'aviso_id' => $aviso, 'establecimiento_id' => $interesado, 'usuario_id' => $usuario,
                'mensaje' => 'Tengo 40 bubillas para entorar. ¿Reciben búfalas y desde cuándo?', 'especie_codigo' => 'BUB', 'cabezas' => 40, 'categorias' => '{BUB_BUBILLA}',
                'desde' => $hoy->copy()->addMonth()->toDateString(), 'hasta' => null, 'contacto_interesado' => $contacto($usuario),
                'consentimiento' => json_encode(['compartir_contacto' => true, 'version' => '2026-10-17', 'aceptado_en' => now()->toIso8601String()]),
                'estado' => 'respondida', 'respuesta' => 'Sí, recibimos búfalas. Coordinemos una visita para ver la manga y el estero.',
                'contacto_anunciante' => $contacto($anunciante), 'respondida_en' => now(), 'created_at' => now()->subDays(2), 'updated_at' => now(),
            ]);
        }
    }
}
