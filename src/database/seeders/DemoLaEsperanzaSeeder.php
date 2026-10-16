<?php

namespace Database\Seeders;

use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarPotrero;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
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
            $this->bucerrasDestetadas();
            $this->bubillosEnVenta();
            $this->sanidadDeLotes();
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
            'vacas' => ['BOV_VACA' => 619], 'vaquillonas' => ['BOV_VAQUILLONA' => 180],
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
