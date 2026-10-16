<?php

namespace App\Domain\Documentos\Almacen;

use DateTimeInterface;

/**
 * Almacén de objetos para la subida diferida (TPA-44). El teléfono sube **directo** al almacén con URLs prefirmadas
 * (no pasa los bytes por la API); la API solo inicia, completa y verifica el SHA-256.
 */
interface AlmacenObjetos
{
    /** URL para un PUT único. */
    public function urlSimple(string $subidaId, string $clave, string $mime, DateTimeInterface $expira): string;

    /**
     * Inicia un multipart y devuelve [upload_id, [numero => url]].
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public function iniciarMultipart(string $subidaId, string $clave, string $mime, int $partes, DateTimeInterface $expira): array;

    /** @param list<array{numero: int, etag: string}> $partes */
    public function completarMultipart(string $subidaId, string $clave, string $uploadId, array $partes): void;

    /** Para subidas simples en almacenes que necesitan ensamblar (local). */
    public function completarSimple(string $subidaId, string $clave): void;

    public function abortar(string $subidaId, string $clave, ?string $uploadId): void;

    /** SHA-256 y tamaño del objeto guardado (null si no existe). @return array{sha256: string, bytes: int}|null */
    public function huella(string $clave): ?array;

    public function borrar(string $clave): void;

    public function urlDescarga(string $clave, DateTimeInterface $expira): ?string;
}
