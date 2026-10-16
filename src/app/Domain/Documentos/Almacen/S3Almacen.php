<?php

namespace App\Domain\Documentos\Almacen;

use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;

/**
 * S3 / MinIO / R2. Las URLs se presignan con un cliente apuntado al **endpoint público** (la firma SigV4 incluye el host);
 * las operaciones de control (crear/completar multipart, leer para verificar) van por el endpoint interno.
 */
final class S3Almacen implements AlmacenObjetos
{
    private function interno(): S3Client
    {
        /** @phpstan-ignore-next-line */
        return Storage::disk('s3')->getClient();
    }

    private function publico(): S3Client
    {
        $c = config('filesystems.disks.s3');

        return new S3Client(['version' => 'latest', 'region' => $c['region'] ?: 'us-east-1', 'endpoint' => config('tropilla.archivos.endpoint_publico'),
            'use_path_style_endpoint' => (bool) $c['use_path_style_endpoint'], 'credentials' => ['key' => $c['key'], 'secret' => $c['secret']]]);
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.s3.bucket');
    }

    public function urlSimple(string $subidaId, string $clave, string $mime, DateTimeInterface $expira): string
    {
        $cmd = $this->publico()->getCommand('PutObject', ['Bucket' => $this->bucket(), 'Key' => $clave, 'ContentType' => $mime]);

        return (string) $this->publico()->createPresignedRequest($cmd, $expira)->getUri();
    }

    public function iniciarMultipart(string $subidaId, string $clave, string $mime, int $partes, DateTimeInterface $expira): array
    {
        $uploadId = (string) $this->interno()->createMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $clave, 'ContentType' => $mime])['UploadId'];
        $pub = $this->publico();
        $urls = [];
        for ($n = 1; $n <= $partes; $n++) {
            $cmd = $pub->getCommand('UploadPart', ['Bucket' => $this->bucket(), 'Key' => $clave, 'UploadId' => $uploadId, 'PartNumber' => $n]);
            $urls[$n] = (string) $pub->createPresignedRequest($cmd, $expira)->getUri();
        }

        return [$uploadId, $urls];
    }

    public function completarMultipart(string $subidaId, string $clave, string $uploadId, array $partes): void
    {
        usort($partes, fn ($a, $b) => $a['numero'] <=> $b['numero']);
        $this->interno()->completeMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $clave, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn ($p) => ['PartNumber' => $p['numero'], 'ETag' => '"'.trim($p['etag'], '"').'"'], $partes)]]);
    }

    public function completarSimple(string $subidaId, string $clave): void {}

    public function abortar(string $subidaId, string $clave, ?string $uploadId): void
    {
        if ($uploadId) {
            rescue(fn () => $this->interno()->abortMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $clave, 'UploadId' => $uploadId]), report: false);
        }
    }

    public function huella(string $clave): ?array
    {
        $disk = Storage::disk('s3');
        if (! $disk->exists($clave)) {
            return null;
        }
        $stream = $disk->readStream($clave);
        $ctx = hash_init('sha256');
        $bytes = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            $bytes += strlen((string) $chunk);
            hash_update($ctx, (string) $chunk);
        }
        fclose($stream);

        return ['sha256' => hash_final($ctx), 'bytes' => $bytes];
    }

    public function borrar(string $clave): void
    {
        Storage::disk('s3')->delete($clave);
    }

    public function urlDescarga(string $clave, DateTimeInterface $expira): ?string
    {
        $cmd = $this->publico()->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $clave]);

        return (string) $this->publico()->createPresignedRequest($cmd, $expira)->getUri();
    }
}
