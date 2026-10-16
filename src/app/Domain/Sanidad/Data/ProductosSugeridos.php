<?php

namespace App\Domain\Sanidad\Data;

/**
 * Base sugerida de productos sanitarios (GET /catalogos/productos-sugeridos, modelo-datos §7).
 * Genéricos por principio activo, **sin dosis y sin retiro**: la dosis la indica el veterinario y el retiro sale del
 * marbete del producto que se compre. El usuario copia la sugerencia a su catálogo y completa esos datos.
 */
final class ProductosSugeridos
{
    /** codigo => [nombre, principio_activo, tipo, via, especies, enfermedades] */
    public const TODOS = [
        'vacuna_aftosa' => ['Vacuna antiaftosa (oleosa)', null, 'vacuna', 'subcutanea', ['BOV', 'BUB'], ['aftosa']],
        'vacuna_rabia' => ['Vacuna antirrábica', null, 'vacuna', 'subcutanea', ['BOV', 'BUB'], ['rabia']],
        'vacuna_carbunclo' => ['Vacuna anticarbunclosa', null, 'vacuna', 'subcutanea', ['BOV', 'BUB'], ['carbunclo']],
        'vacuna_clostridiales' => ['Vacuna clostridial polivalente', null, 'vacuna', 'subcutanea', ['BOV', 'BUB'], ['clostridiales']],
        'vacuna_brucelosis_rb51' => ['Vacuna antibrucélica (RB51 / Cepa 19)', null, 'vacuna', 'subcutanea', ['BOV', 'BUB'], ['brucelosis']],
        'vacuna_reproductiva' => ['Vacuna reproductiva (IBR/DVB/leptospira)', null, 'vacuna', 'intramuscular', ['BOV', 'BUB'], ['reproductivas']],
        'ivermectina' => ['Ivermectina inyectable', 'ivermectina', 'antiparasitario_interno', 'subcutanea', ['BOV', 'BUB'], ['parasitos_internos', 'parasitos_externos']],
        'doramectina' => ['Doramectina inyectable', 'doramectina', 'antiparasitario_interno', 'subcutanea', ['BOV', 'BUB'], ['parasitos_internos', 'parasitos_externos']],
        'albendazol' => ['Albendazol oral', 'albendazol', 'antiparasitario_interno', 'oral', ['BOV', 'BUB'], ['parasitos_internos']],
        'levamisol' => ['Levamisol', 'levamisol', 'antiparasitario_interno', 'subcutanea', ['BOV', 'BUB'], ['parasitos_internos']],
        'closantel' => ['Closantel (fasciolicida)', 'closantel', 'antiparasitario_interno', 'subcutanea', ['BOV', 'BUB'], ['parasitos_internos']],
        'triclabendazol' => ['Triclabendazol (fasciolicida)', 'triclabendazol', 'antiparasitario_interno', 'oral', ['BOV', 'BUB'], ['parasitos_internos']],
        'fluazuron' => ['Fluazurón pour-on (garrapaticida)', 'fluazuron', 'antiparasitario_externo', 'pour_on', ['BOV'], ['parasitos_externos']],
        'cipermetrina' => ['Cipermetrina pour-on', 'cipermetrina', 'antiparasitario_externo', 'pour_on', ['BOV', 'BUB'], ['parasitos_externos']],
        'fipronil' => ['Fipronil pour-on', 'fipronil', 'antiparasitario_externo', 'pour_on', ['BOV'], ['parasitos_externos']],
        'oxitetraciclina' => ['Oxitetraciclina LA', null, 'antibiotico', 'intramuscular', ['BOV', 'BUB'], ['queratoconjuntivitis', 'otra']],
        'tuberculina_ppd' => ['Tuberculina PPD bovina', null, 'otro', 'otra', ['BOV', 'BUB'], ['tuberculosis']],
    ];

    /** @return list<array<string, mixed>> */
    public static function listar(?string $especie = null): array
    {
        $out = [];
        foreach (self::TODOS as $codigo => [$nombre, $activo, $tipo, $via, $especies, $enfermedades]) {
            if ($especie !== null && ! in_array($especie, $especies, true)) {
                continue;
            }
            $out[] = [
                'id' => null, 'codigo' => $codigo, 'nombre_comercial' => $nombre, 'principio_activo' => $activo, 'tipo' => $tipo, 'via' => $via,
                'dosis_declarada' => null, 'retiro_carne_dias' => null, 'retiro_leche_dias' => null, 'especies' => $especies,
                'enfermedades' => $enfermedades, 'notas' => __('Completá la dosis que indique tu veterinario y el retiro del marbete.'),
                'archivado_en' => null, 'row_version' => 0,
            ];
        }

        return $out;
    }
}
