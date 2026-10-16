<?php

use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Merkle;

/*
| Vectores normativos TROPILLA-CJ/1 (brain/technical/vectores-hash-v1.json, los mismos que usa tropilla-flutter).
| Si alguno falla, la implementación está mal (sync-e-integridad.md §9).
*/

function vectores(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../fixtures/vectores-hash-v1.json'), true);
}

it('canonicaliza y hashea los 6 vectores idéntico a Dart/Python', function (array $v) {
    $canonico = CanonicalJson::encode(CanonicalJson::decode($v['entrada']));

    expect($canonico)->toBe($v['canonico'])
        ->and(strlen($canonico))->toBe($v['canonico_utf8_bytes'])
        ->and(CanonicalJson::huella(CanonicalJson::decode($v['entrada'])))->toBe($v['huella']);
})->with(fn () => array_map(fn ($v) => [$v], array_combine(array_column(vectores()['vectores'], 'nombre'), vectores()['vectores'])));

it('rechaza los 6 negativos', function (string $entrada) {
    expect(fn () => CanonicalJson::encode(CanonicalJson::decode($entrada)))->toThrow(CanonicalizacionInvalida::class);
})->with(fn () => array_column(vectores()['negativos'], 'entrada'));

it('encadena los eslabones de la cadena de ejemplo', function () {
    $anterior = str_repeat('0', 64);
    foreach (vectores()['cadena'] as $c) {
        expect($c['eslabon_anterior'])->toBe($anterior)
            ->and(CanonicalJson::eslabon($anterior, $c['huella']))->toBe($c['eslabon']);
        $anterior = $c['eslabon'];
    }
});

it('calcula raíces y pruebas de Merkle RFC 6962', function () {
    $m = vectores()['merkle'];

    expect(Merkle::raiz($m['hojas']))->toBe($m['raiz'])
        ->and(Merkle::raiz(array_slice($m['hojas'], 0, 3)))->toBe($m['raiz_3'])
        ->and(Merkle::raiz([$m['hojas'][0]]))->toBe($m['raiz_1'])
        ->and(Merkle::prueba(2, array_slice($m['hojas'], 0, 3)))->toBe($m['prueba_3_idx2']);

    foreach ($m['pruebas'] as $i => $prueba) {
        expect(Merkle::prueba((int) $i, $m['hojas']))->toBe($prueba)
            ->and(Merkle::verificar($m['hojas'][$i], $prueba, $m['raiz']))->toBeTrue();
    }
    expect(Merkle::verificar($m['hojas'][0], $m['pruebas']['1'], $m['raiz']))->toBeFalse();
});

it('{} y [] no son intercambiables y los null de arrays se conservan', function () {
    expect(CanonicalJson::encode(CanonicalJson::decode('{"a":{},"b":[],"c":[null],"d":null}')))->toBe('{"a":{},"b":[],"c":[null]}')
        ->and(CanonicalJson::encode(['x' => new stdClass, 'y' => []]))->toBe('{"x":{},"y":[]}');
});
