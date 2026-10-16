<?php

namespace Database\Seeders;

use App\Domain\Comercial\Actions\GestionarOferta;
use App\Domain\Comercial\Actions\RecibirConsultaPublica;
use App\Domain\Contratos\Actions\GestionarContrato;
use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarPotrero;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Perfil\Actions\GestionarPerfil;
use App\Domain\Perfil\Services\Perfiles;
use App\Domain\Sanidad\Actions\GestionarPlan;
use App\Domain\Sanidad\Actions\GestionarTarea;
use App\Domain\Sanidad\Actions\GuardarProductoSanitario;
use App\Domain\Sanidad\Models\PlanItem;
use App\Domain\Sanidad\Models\Tarea;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos DEMO de brain/design/prompt-claude-design.md §4 — «Agropecuaria Don Ramón S.A.», establecimiento
 * «La Esperanza» (General Paz, Corrientes, 2.400 ha). Todo se carga **por los mismos caminos que la app**
 * (onboarding, potreros y eventos hasheados y encadenados): las existencias, el lote en venta, la búfala
 * U-0142 y la vaca 1187 salen de eventos reales, no de inserts en las proyecciones.
 *
 * Usuario: demo@tropilla.ar / tropilla-demo (dueño). Idempotente: si el establecimiento ya existe, no hace nada.
 *
 *   docker compose exec app php artisan db:seed --class=DemoLaEsperanzaSeeder
 */
class DemoLaEsperanzaSeeder extends Seeder
{
    public const EMAIL = 'demo@tropilla.ar';

    public const PASSWORD = 'tropilla-demo';

    private Establecimiento $est;

    private User $dueno;

    /** @var array<string, string> */
    private array $potreros = [];

    /** @var array<string, string> */
    private array $lotes = [];

    public function run(): void
    {
        $this->dueno = User::query()->firstOrCreate(['email' => self::EMAIL], [
            'nombre' => 'Ramón Gómez', 'password' => self::PASSWORD, 'email_verificado_en' => now(), 'idioma' => 'es',
            'telefono' => '+5493794000142',
        ]);
        if (DB::table('membresias')->where('usuario_id', $this->dueno->id)->where('estado', 'activa')->exists()) {
            $this->command?->info('Demo La Esperanza ya cargado: no se toca.');

            return;
        }

        DB::transaction(function () {
            $this->establecimiento();
            $this->potreros();
            $this->lotes();
            $this->rodeosAnonimos();
            $this->bufalaU0142();
            $this->vaca1187();
            $this->bufalasPorParir();
            $this->vacasDeServicio();
            $this->bucerrasDestetadas();
            $this->bubillosEnVenta();
            $this->sanidadDeLotes();
            $this->planSanitario();
            $this->ofertaBubillos();
            $this->perfilPublico();
            $this->contratoFuturo();
        });

        $this->command?->info('Demo La Esperanza cargado: '.self::EMAIL.' / '.self::PASSWORD);
    }

    private function establecimiento(): void
    {
        $this->est = app(RealizarOnboarding::class)->handle($this->dueno, [
            'productor' => [
                'tipo' => 'sociedad', 'nombre' => 'Agropecuaria Don Ramón S.A.', 'cuit' => '30712345671',
                'contacto' => ['telefono' => '+5493794000142', 'email' => 'contacto@donramon.example', 'whatsapp' => '+5493794000142'],
                'asociaciones' => [['entidad' => 'AACB', 'numero_socio' => '0421']],
            ],
            'establecimiento' => [
                'nombre' => 'La Esperanza', 'provincia_codigo' => '18', 'departamento_codigo' => '18063', 'localidad' => 'Caá Catí',
                'ubicacion' => ['lat' => '-27.7512', 'lon' => '-57.6213'], 'renspa' => '18.063.0.00421/00', 'superficie_ha' => '2400',
                'actividades' => ['cria', 'recria', 'invernada'], 'especies' => ['BOV', 'BUB'],
                'instalaciones' => ['manga', 'balanza', 'cepo', 'embarcadero', 'aguadas', 'revolcaderos'],
                'zonas_sanitarias' => ['aftosa_zona' => 'Zona libre con vacunación', 'garrapata' => 'control', 'rabia_endemica' => true, 'fasciola_endemica' => true],
                'historia' => 'Campo familiar en General Paz. Cría bovina desde 1968 y búfalos Murrah y Mediterráneos desde 2009.',
            ],
        ]);
    }

    private function potreros(): void
    {
        $guardar = app(GuardarPotrero::class);
        foreach ([
            'banado' => ['nombre' => 'Bañado Norte', 'superficie_ha' => '620', 'ambiente' => 'banado', 'recurso_forrajero' => 'campo_natural', 'agua' => true, 'revolcadero' => true],
            'loma' => ['nombre' => 'Loma del Monte', 'superficie_ha' => '850', 'ambiente' => 'loma', 'recurso_forrajero' => 'pastura_implantada', 'agua' => true, 'revolcadero' => false],
            'malezal' => ['nombre' => 'Malezal 3', 'superficie_ha' => '540', 'ambiente' => 'malezal', 'recurso_forrajero' => 'campo_natural', 'agua' => true, 'revolcadero' => false],
        ] as $clave => $datos) {
            $this->potreros[$clave] = $guardar->crear($this->est, $this->dueno, $datos)['potrero']->id;
        }
    }

    private function lotes(): void
    {
        $lotes = [
            'vacas' => ['Vacas de cría', 'BOV', 'cria', true], 'vaquillonas' => ['Vaquillonas', 'BOV', 'recria', true],
            'terneros' => ['Terneros 2026', 'BOV', 'recria', true], 'novillos' => ['Novillos invernada', 'BOV', 'engorde', true],
            'toros' => ['Toros', 'BOV', 'reproductores', true], 'bufalas' => ['Búfalas Murrah', 'BUB', 'cria', true],
            'bubillas' => ['Bubillas', 'BUB', 'recria', true], 'bucerros' => ['Bucerros 2026', 'BUB', 'recria', true],
            'bubillos' => ['Bubillos Mediterráneos (venta)', 'BUB', 'venta', false], 'bufalos' => ['Búfalos toro', 'BUB', 'reproductores', true],
        ];
        foreach ($lotes as $clave => [$nombre, $especie, $proposito, $anonimo]) {
            $id = (string) Str::uuid7();
            DB::table('lotes')->insert([
                'id' => $id, 'establecimiento_id' => $this->est->id, 'nombre' => $nombre, 'especie_codigo' => $especie,
                'proposito' => $proposito, 'anonimo' => $anonimo, 'row_version' => 1, 'creado_por' => $this->dueno->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(RegistrarCambio::class)->handle($this->est->id, 'lote', $id);
            $this->lotes[$clave] = $id;
        }
    }

    /** Existencias del §4 como lotes anónimos (menos los animales individualizados más abajo). */
    private function rodeosAnonimos(): void
    {
        $conteos = [
            'vacas' => ['BOV_VACA' => 617], 'vaquillonas' => ['BOV_VAQUILLONA' => 180],
            'terneros' => ['BOV_TERNERO' => 155, 'BOV_TERNERA' => 155], 'novillos' => ['BOV_NOVILLO' => 110],
            'toros' => ['BOV_TORO' => 20], 'bufalas' => ['BUB_BUFALA' => 185], 'bubillas' => ['BUB_BUBILLA' => 60],
            'bucerros' => ['BUB_BUCERRO' => 48, 'BUB_BUCERRA' => 39], 'bufalos' => ['BUB_BUFALO_TORO' => 5],
        ];
        $potreroDe = ['vacas' => 'loma', 'vaquillonas' => 'loma', 'terneros' => 'loma', 'novillos' => 'malezal', 'toros' => 'loma',
            'bufalas' => 'banado', 'bubillas' => 'banado', 'bucerros' => 'banado', 'bufalos' => 'banado', 'bubillos' => 'malezal'];

        foreach ($conteos as $lote => $conteo) {
            $this->evento('hacienda.alta', ['tipo' => 'lote', 'lote_id' => $this->lotes[$lote], 'conteo' => $conteo],
                ['motivo' => 'inventario_inicial'], '2026-01-15');
        }
        foreach ($potreroDe as $lote => $potrero) {
            $this->evento('hacienda.traslado', ['tipo' => 'lote', 'lote_id' => $this->lotes[$lote]],
                ['potrero_destino_id' => $this->potreros[$potrero]], '2026-01-16');
        }
        // Dos pesadas promedio de los novillos (GDP promedio del lote).
        $this->evento('hacienda.pesaje', ['tipo' => 'lote', 'lote_id' => $this->lotes['novillos']],
            ['modo' => 'promedio', 'peso_kg' => '388', 'cantidad' => 110, 'metodo' => 'balanza'], '2026-07-10');
        $this->evento('hacienda.pesaje', ['tipo' => 'lote', 'lote_id' => $this->lotes['novillos']],
            ['modo' => 'promedio', 'peso_kg' => '421.5', 'cantidad' => 110, 'metodo' => 'balanza'], '2026-09-10');
    }

    /** Búfala Murrah U-0142: 6 años, 590 kg, preñada (tacto 25/09/2026) → parto probable 12/01/2027 (Murrah 303 d). */
    private function bufalaU0142(): void
    {
        $id = (string) Str::uuid7();
        $this->alta([[
            'animal_id' => $id, 'especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUFALA', 'raza' => 'BUB_MURRAH',
            'fecha_nacimiento' => '2020-09-20', 'nacimiento_estimado' => true, 'denticion' => '6_mas', 'lote_id' => $this->lotes['bufalas'],
            'identificaciones' => [
                ['tipo' => 'tatuaje', 'valor' => 'U-0142'], ['tipo' => 'caravana_visual', 'valor' => '0142'],
                ['tipo' => 'rfid', 'valor' => '032 000123456789'],
            ],
            'senas' => 'Cuerno izquierdo levemente caído.',
        ]], '2026-01-15');
        $a = ['tipo' => 'animales', 'animal_ids' => [$id], 'lote_id' => $this->lotes['bufalas']];
        $this->evento('hacienda.traslado', $a, ['potrero_destino_id' => $this->potreros['banado']], '2026-01-16');
        $this->evento('reproduccion.servicio_natural', $a, ['inicio' => '2026-03-10', 'fin' => '2026-05-30'], '2026-03-10');
        $this->evento('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$id]], ['modo' => 'individual', 'peso_kg' => '574', 'metodo' => 'balanza'], '2026-06-12');
        $this->evento('reproduccion.tacto', ['tipo' => 'animales', 'animal_ids' => [$id]],
            ['metodo' => 'ecografia', 'resultado' => 'prenada', 'dias_gestacion' => 194, 'veterinario' => 'Vet. Juan Pérez, MP 1234'], '2026-09-25');
        $this->evento('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$id]], ['modo' => 'individual', 'peso_kg' => '590', 'metodo' => 'balanza'], '2026-09-25');
        $this->evento('hacienda.condicion_corporal', ['tipo' => 'animales', 'animal_ids' => [$id]], ['valor' => '6', 'escala' => '1-9'], '2026-09-25');
        $this->evento('sanidad.vacunacion', ['tipo' => 'animales', 'animal_ids' => [$id]], [
            'producto_nombre' => 'Vacuna antiaftosa oleosa', 'enfermedad' => 'aftosa', 'numero_dosis' => 'anual', 'campana' => '2026-2',
            'via' => 'subcutanea', 'vacunador' => 'Fundación Corrientes (declarado)', 'retiro_carne_dias' => 0,
        ], '2026-10-02');
    }

    /** Vaca Braford 1187: vacía, 455 kg. */
    private function vaca1187(): void
    {
        $id = (string) Str::uuid7();
        $this->alta([[
            'animal_id' => $id, 'especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_BRAFORD',
            'fecha_nacimiento' => '2019-10-01', 'nacimiento_estimado' => true, 'lote_id' => $this->lotes['vacas'],
            'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '1187']],
        ]], '2026-01-15');
        $s = ['tipo' => 'animales', 'animal_ids' => [$id]];
        $this->evento('hacienda.traslado', $s, ['potrero_destino_id' => $this->potreros['loma']], '2026-01-16');
        $this->evento('reproduccion.tacto', $s, ['metodo' => 'tacto', 'resultado' => 'vacia'], '2026-04-20');
        $this->evento('hacienda.pesaje', $s, ['modo' => 'individual', 'peso_kg' => '455', 'metodo' => 'balanza'], '2026-09-30');
    }

    /** 4 búfalas con parto probable esta semana (pendiente del inicio). */
    private function bufalasPorParir(): void
    {
        foreach ([1, 2, 4, 6] as $i => $enDias) {
            $id = (string) Str::uuid7();
            $this->alta([[
                'animal_id' => $id, 'especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUFALA', 'raza' => 'BUB_MURRAH',
                'fecha_nacimiento' => '2019-0'.($i + 3).'-15', 'nacimiento_estimado' => true, 'lote_id' => $this->lotes['bufalas'],
                'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '02'.(10 + $i)], ['tipo' => 'tatuaje', 'valor' => 'U-02'.(10 + $i)]],
            ]], '2026-01-15');
            // Tacto del 25/09 con días calculados para que la FPP (Murrah 303 d) caiga en la próxima semana.
            $fpp = now()->startOfDay()->addDays($enDias);
            $dias = 303 - (int) now()->setDate(2026, 9, 25)->startOfDay()->diffInDays($fpp, true);
            $this->evento('reproduccion.tacto', ['tipo' => 'animales', 'animal_ids' => [$id]],
                ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => max(1, $dias)], '2026-09-25');
        }
    }

    /**
     * TPA-30: dos vacas Braford individualizadas del rodeo (por eso el lote anónimo tiene 617 y el total sigue en 620):
     * la 0904 inseminada (IATF, servida) y la 0731 preñada con la fecha probable de parto vencida hace 20 días (alerta).
     */
    private function vacasDeServicio(): void
    {
        $iatf = (string) Str::uuid7();
        $vencida = (string) Str::uuid7();
        $this->alta([
            ['animal_id' => $iatf, 'especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_BRAFORD', 'lote_id' => $this->lotes['vacas'],
                'fecha_nacimiento' => '2020-09-01', 'nacimiento_estimado' => true, 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0904']]],
            ['animal_id' => $vencida, 'especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VACA', 'raza' => 'BOV_BRAFORD', 'lote_id' => $this->lotes['vacas'],
                'fecha_nacimiento' => '2018-10-01', 'nacimiento_estimado' => true, 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0731']]],
        ], '2026-01-15');
        $this->evento('reproduccion.inseminacion', ['tipo' => 'animales', 'animal_ids' => [$iatf]], [
            'tipo' => 'iatf', 'semen' => ['toro_nombre' => 'Braford Don Gaspar', 'raza' => 'BOV_BRAFORD', 'centro' => 'Centro de IA (declarado)'],
            'inseminador' => 'Vet. Juan Pérez',
        ], now()->startOfDay()->subDays(25)->toDateString());
        // Tacto hace 60 días con 243 días de gestación → FPP hace 20 días (bovino 283 d): parto vencido (+15 d).
        $this->evento('reproduccion.tacto', ['tipo' => 'animales', 'animal_ids' => [$vencida]],
            ['metodo' => 'tacto', 'resultado' => 'prenada', 'dias_gestacion' => 243, 'veterinario' => 'Vet. Juan Pérez, MP 1234'], now()->startOfDay()->subDays(60)->toDateString());
    }

    /** 8 bucerras destetadas → recategorización sugerida a bubilla (pendiente del inicio). */
    private function bucerrasDestetadas(): void
    {
        $animales = [];
        foreach (range(1, 8) as $i) {
            $animales[] = [
                'animal_id' => (string) Str::uuid7(), 'especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUCERRA', 'raza' => 'BUB_MURRAH',
                'fecha_nacimiento' => '2026-02-'.str_pad((string) (2 + $i), 2, '0', STR_PAD_LEFT), 'lote_id' => $this->lotes['bucerros'],
                'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'B26-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]],
            ];
        }
        $this->alta($animales, '2026-03-01');
        $ids = array_column($animales, 'animal_id');
        sort($ids);
        $this->evento('reproduccion.destete', ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $this->lotes['bucerros']],
            ['tipo' => 'tradicional', 'peso_kg' => '165'], '2026-10-05');
    }

    /** Lote en venta: 30 bubillos Mediterráneos de 24–28 meses, 4 dientes; promedio 468 kg (balanza, 10/10/2026). */
    private function bubillosEnVenta(): void
    {
        $animales = [];
        $pesos = [];
        foreach (range(1, 30) as $i) {
            $animales[] = [
                'animal_id' => (string) Str::uuid7(), 'especie' => 'BUB', 'sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true,
                'raza' => 'BUB_MEDITERRANEA', 'denticion' => '4', 'lote_id' => $this->lotes['bubillos'],
                'fecha_nacimiento' => now()->setDate(2024, 6, 10)->addDays(($i * 4) % 120)->toDateString(), 'nacimiento_estimado' => true,
                'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'M-'.str_pad((string) (300 + $i), 4, '0', STR_PAD_LEFT)]],
            ];
            $pesos[] = 468 + [-10, -6, -2, 2, 6, 10][$i % 6]; // 458..478, promedio exacto 468
        }
        $this->alta($animales, '2026-01-15', 'ingreso');
        $ids = array_column($animales, 'animal_id');
        sort($ids);
        $this->evento('hacienda.traslado', ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $this->lotes['bubillos']],
            ['potrero_destino_id' => $this->potreros['malezal']], '2026-01-16');
        foreach ($animales as $i => $a) {
            $this->evento('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
                ['modo' => 'individual', 'peso_kg' => (string) ($pesos[$i] - 42), 'metodo' => 'balanza'], '2026-08-10');
            $this->evento('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
                ['modo' => 'individual', 'peso_kg' => (string) $pesos[$i], 'metodo' => 'balanza'], '2026-10-10');
        }
        $media = array_sum($pesos) / count($pesos);
        $this->evento('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $this->lotes['bubillos']], [
            'modo' => 'promedio', 'peso_kg' => rtrim(rtrim(number_format($media, 1, '.', ''), '0'), '.'), 'cantidad' => 30,
            'peso_min_kg' => (string) min($pesos), 'peso_max_kg' => (string) max($pesos), 'metodo' => 'balanza',
        ], '2026-10-10');
        $this->evento('sanidad.vacunacion', ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $this->lotes['bubillos']], [
            'producto_nombre' => 'Vacuna antirrábica', 'enfermedad' => 'rabia', 'numero_dosis' => 'anual', 'via' => 'subcutanea', 'retiro_carne_dias' => 0,
        ], '2026-04-15');
    }

    /** Vacunaciones grupales a lotes anónimos (aftosa 2ª campaña a menores, rabia a bucerros). */
    private function sanidadDeLotes(): void
    {
        foreach (['terneros', 'vaquillonas', 'bucerros', 'bubillas'] as $lote) {
            $this->evento('sanidad.vacunacion', ['tipo' => 'lote', 'lote_id' => $this->lotes[$lote]], [
                'producto_nombre' => 'Vacuna antiaftosa oleosa', 'enfermedad' => 'aftosa', 'numero_dosis' => 'anual', 'campana' => '2026-2',
                'via' => 'subcutanea', 'vacunador' => 'Fundación Corrientes (declarado)',
            ], '2026-10-02');
        }
        $this->evento('sanidad.vacunacion', ['tipo' => 'lote', 'lote_id' => $this->lotes['bucerros'], 'conteo' => ['BUB_BUCERRO' => 12]], [
            'producto_nombre' => 'Vacuna antirrábica', 'enfermedad' => 'rabia', 'numero_dosis' => 'primera', 'via' => 'subcutanea',
        ], now()->subDays(27)->toDateString());
    }

    /**
     * Plan sanitario desde plantillas (TPA-33) con el aviso veterinario aceptado por el dueño, ventanas de aftosa de la zona
     * y productos del establecimiento (TPA-31). Deja pendientes reales: refuerzo de rabia de los bucerros (primera dosis hace
     * 27 días) y las campañas/ventanas que caigan en los próximos 90 días.
     */
    private function planSanitario(): void
    {
        $productos = app(GuardarProductoSanitario::class);
        foreach ([
            ['nombre_comercial' => 'Vacuna antiaftosa oleosa', 'tipo' => 'vacuna', 'via' => 'subcutanea', 'dosis_declarada' => 'Según SENASA / vacunador', 'especies' => ['BOV', 'BUB'], 'enfermedades' => ['aftosa'], 'sugerido_codigo' => 'vacuna_aftosa'],
            ['nombre_comercial' => 'Vacuna antirrábica', 'tipo' => 'vacuna', 'via' => 'subcutanea', 'retiro_carne_dias' => 0, 'especies' => ['BOV', 'BUB'], 'enfermedades' => ['rabia'], 'sugerido_codigo' => 'vacuna_rabia'],
            ['nombre_comercial' => 'Doramectina 1%', 'principio_activo' => 'doramectina', 'tipo' => 'antiparasitario_interno', 'via' => 'subcutanea',
                'dosis_declarada' => 'La indicada por el Dr. Pérez', 'retiro_carne_dias' => 35, 'especies' => ['BOV', 'BUB'], 'enfermedades' => ['parasitos_internos', 'parasitos_externos'], 'sugerido_codigo' => 'doramectina'],
        ] as $p) {
            $productos->crear($this->est, $this->dueno, $p);
        }

        $plan = app(GestionarPlan::class);
        $plan->aplicarPlantillas($this->est, $this->dueno, ['aftosa_campana_1', 'aftosa_campana_2', 'rabia_paresiante', 'bub_toxocara_neonatal',
            'bub_fasciolicida', 'bub_pediculosis', 'garrapata_rotacion']);
        $configurar = [
            'aftosa_campana_1' => ['tipo' => 'ventana', 'desde' => '03-01', 'hasta' => '04-30', 'configurable' => true],
            'aftosa_campana_2' => ['tipo' => 'ventana', 'desde' => '10-01', 'hasta' => '11-30', 'configurable' => true],
            'rabia_paresiante' => ['tipo' => 'fecha_fija', 'fecha' => '04-15', 'configurable' => true],
        ];
        foreach ($configurar as $codigo => $disparador) {
            $item = PlanItem::query()->where('establecimiento_id', $this->est->id)->where('plantilla_codigo', $codigo)->sole();
            $plan->editarItem($this->est, $item, $this->dueno, ['row_version' => $item->row_version, 'disparador' => $disparador]);
        }
        // La 2ª campaña de este año ya se aplicó (02/10) antes de armar el plan.
        $campana2 = Tarea::query()->join('plan_items as i', 'i.id', '=', 'tareas.plan_item_id')->where('i.establecimiento_id', $this->est->id)
            ->where('i.plantilla_codigo', 'aftosa_campana_2')->where('tareas.clave_ocurrencia', (string) now()->year)->where('tareas.estado', 'pendiente')->first(['tareas.*']);
        if ($campana2) {
            app(GestionarTarea::class)->omitir($this->est, $campana2, $this->dueno, 'Aplicada el 02/10 a los lotes de menores (cargada antes de armar el plan).');
        }
    }

    /** Oferta publicada del lote en venta (ficha pública /o/{codigo}, hasheada y encadenada). */
    private function ofertaBubillos(): void
    {
        $gestionar = app(GestionarOferta::class);
        $oferta = $gestionar->crear($this->est, $this->dueno, [
            'tipo' => 'gordo', 'lote_id' => $this->lotes['bubillos'],
            'condiciones' => ['modalidad' => 'en_pie', 'mostrar_precio' => false, 'lugar_carga' => 'General Paz, Corrientes'],
            'textos' => [
                'es' => ['titulo' => '30 bubillos Mediterráneos', 'descripcion' => 'Bubillos de 24 a 28 meses, terminados a pasto en humedales del NEA. Pesada en balanza del 10/10/2026.'],
                'en' => ['titulo' => '30 Mediterranean buffalo steers', 'descripcion' => '24–28 month old buffalo steers, grass-finished in NEA wetlands. Scale-weighed on 2026-10-10.'],
                'pt' => ['titulo' => '30 bubilhos Mediterrâneos', 'descripcion' => 'Bubilhos de 24 a 28 meses, terminados a pasto em áreas úmidas do NEA.'],
            ],
        ]);
        $gestionar->publicar($oferta, $this->dueno, true);

        // 2 consultas de compradores sin responder (pendientes del inicio).
        $recibir = app(RecibirConsultaPublica::class);
        foreach ([
            ['nombre' => 'Li Wei', 'empresa' => 'Shandong Premium Meat', 'pais' => 'CN', 'email' => 'li.wei@example.com', 'tipo_comprador' => 'exportador',
                'volumen' => '2 contenedores/mes', 'mensaje' => 'Hello, we are interested in buffalo steers for export. Can you share health records and availability?', 'idioma' => 'en'],
            ['nombre' => 'Marcelo Duarte', 'empresa' => 'Frigorífico del Litoral', 'pais' => 'AR', 'telefono' => '+5493794555123', 'tipo_comprador' => 'frigorifico',
                'volumen' => '30 cabezas', 'mensaje' => 'Buen día, ¿pueden cargar la semana próxima? ¿Precio al gancho?', 'idioma' => 'es'],
        ] as $c) {
            $recibir->handle([...$c, 'oferta_codigo' => $oferta->codigo_publico, 'captcha_token' => 'demo'], null, 'seeder', null);
        }
    }

    /**
     * Contrato a futuro de ejemplo (TPA-56): las 60 bubillas, 450 kg a la entrega (en 8 meses), 53 % de rendimiento → 14.310 kg al gancho
     * = 143 tokens de 100 kg; referencia $5.200/kg gancho con 8 % de descuento y 30 % de anticipo. Publicado (huella + PDF si hay Gotenberg).
     */
    private function contratoFuturo(): void
    {
        $g = app(GestionarContrato::class);
        $id = $g->crear($this->est, $this->dueno, ['lote_id' => $this->lotes['bubillas'], 'peso_vivo_estimado_kg' => '450', 'precio_referencia_kg' => '5200',
            'descuento_pct' => '8', 'anticipo_pct' => '30', 'fecha_entrega' => now()->addMonths(8)->startOfMonth()->toDateString()]);
        $g->publicar($this->est, $this->dueno, DB::table('contratos_futuro')->where('id', $id)->first());
    }

    /** Perfil público publicado y en el directorio (`/p/la-esperanza`). */
    private function perfilPublico(): void
    {
        $gestionar = app(GestionarPerfil::class);
        $p = Perfiles::asegurar($this->est);
        $gestionar->editar($this->est, $this->dueno, [
            'row_version' => (int) $p->row_version, 'slug' => 'la-esperanza', 'en_directorio' => true, 'contacto_visible' => ['whatsapp' => true],
            'textos' => [
                'es' => ['titulo' => 'La Esperanza — Agropecuaria Don Ramón', 'descripcion' => 'Cría, recría e invernada de bovinos y búfalos Murrah y Mediterráneos en humedales de General Paz, Corrientes.'],
                'en' => ['titulo' => 'La Esperanza — Agropecuaria Don Ramón', 'descripcion' => 'Cattle and Murrah and Mediterranean buffalo breeding and finishing in the wetlands of General Paz, Corrientes, Argentina.'],
                'pt' => ['titulo' => 'La Esperanza — Agropecuaria Don Ramón', 'descripcion' => 'Cria, recria e engorda de bovinos e búfalos Murrah e Mediterrâneos nas áreas úmidas de General Paz, Corrientes.'],
            ],
        ]);
        $gestionar->aceptarPreview($this->est, $this->dueno);
        $gestionar->publicar($this->est, $this->dueno);
    }

    /** @param list<array<string, mixed>> $animales */
    private function alta(array $animales, string $fecha, string $tipo = 'alta'): void
    {
        $ids = array_column($animales, 'animal_id');
        sort($ids);
        $payload = $tipo === 'ingreso'
            ? ['motivo' => 'compra', 'animales' => $animales, 'origen' => ['vendedor' => 'Cabaña Santa Rosa (declarado)', 'localidad' => 'Mercedes']]
            : ['motivo' => 'inventario_inicial', 'animales' => $animales];
        $this->evento("hacienda.{$tipo}", ['tipo' => 'animales', 'animal_ids' => $ids], $payload, $fecha);
    }

    /**
     * Registra un evento por el mismo camino que la web (sobre, huella, cadena, proyección).
     *
     * @param  array<string, mixed>  $sujetos
     * @param  array<string, mixed>  $payload
     */
    private function evento(string $tipo, array $sujetos, array $payload, string $fecha): void
    {
        $in = json_decode(json_encode(['tipo' => $tipo, 'sujetos' => $sujetos, 'payload' => $payload ?: new \stdClass, 'ocurrido_en' => $fecha]), false);
        app(RegistrarEvento::class)->handle($this->est, $this->dueno, ArmarSobreWeb::alta($this->est, $this->dueno, $in), [
            'canal' => 'web', 'confirmar_advertencias' => true,
        ]);
    }
}
