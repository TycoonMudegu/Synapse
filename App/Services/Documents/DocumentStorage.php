<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Env;
use App\Core\HttpException;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;

final class DocumentStorage
{
    public function __construct(private ?StorageClient $client = null) {}

    public static function enabled(): bool
    {
        return Env::get('DOCUMENT_STORAGE', 'local') === 'gcs';
    }

    public static function isCloud(array $document): bool
    {
        return ($document['storage_driver'] ?? 'local') === 'gcs';
    }

    public static function assertAvailable(array $document): void
    {
        if (!empty($document['archived_at'])) {
            throw new HttpException(
                'This document is in cold storage. Add it to favorites to restore access.',
                409,
            );
        }
    }

    public function changeClass(array $document, string $storageClass): array
    {
        if (!in_array($storageClass, ['STANDARD', 'NEARLINE', 'COLDLINE', 'ARCHIVE'], true)) {
            throw new HttpException('Choose a valid cloud document storage class.', 503);
        }
        return $this->remote(function () use ($document, $storageClass): array {
            $object = $this->object($document['storage_path'], $document['storage_bucket']);
            $before = $object->reload();
            if (
                empty($before['generation']) ||
                empty($before['md5Hash']) ||
                (int) ($before['size'] ?? -1) !== (int) $document['byte_size']
            ) {
                throw new HttpException('The cloud document could not be verified.', 503);
            }
            $metadata = $before['metadata'] ?? [];
            // Recover a completed rewrite if the worker stopped before the DB commit.
            // Random object names are immutable; only this operation replaces them.
            if (
                (string) $before['generation'] !== (string) $document['storage_generation'] &&
                ((string) ($metadata['synapse_document_id'] ?? '') !== (string) $document['id'] ||
                    (string) ($metadata['synapse_source_generation'] ?? '') !==
                        (string) $document['storage_generation'] ||
                    ($metadata['synapse_source_md5'] ?? '') !== ($before['md5Hash'] ?? ''))
            ) {
                throw new HttpException(
                    'The cloud document changed. Please try again after checking storage.',
                    409,
                );
            }
            $after = $before;
            if (($before['storageClass'] ?? '') !== $storageClass) {
                $rewritten = $object->rewrite($document['storage_bucket'], [
                    'ifGenerationMatch' => (string) $before['generation'],
                    'ifSourceGenerationMatch' => (string) $before['generation'],
                    'storageClass' => $storageClass,
                    'metadata' => array_replace($metadata, [
                        'synapse_document_id' => (string) $document['id'],
                        'synapse_source_generation' => (string) $document['storage_generation'],
                        'synapse_source_md5' => $before['md5Hash'],
                    ]),
                    'contentType' => $document['mime_type'],
                    'cacheControl' => 'private, no-store',
                    'contentDisposition' => 'attachment',
                ]);
                $after = $rewritten->info();
            }
            if (
                ($after['storageClass'] ?? '') !== $storageClass ||
                !hash_equals($before['md5Hash'], $after['md5Hash'] ?? '') ||
                (int) ($after['size'] ?? -1) !== (int) $document['byte_size']
            ) {
                throw new HttpException(
                    'The cloud document storage transition could not be verified.',
                    503,
                );
            }
            return [
                'storage_driver' => 'gcs',
                'storage_bucket' => $document['storage_bucket'],
                'storage_path' => $document['storage_path'],
                'storage_generation' => (string) $after['generation'],
                'storage_class' => $storageClass,
            ];
        });
    }

    public function objectName(int $userId, string $extension): string
    {
        $prefix = trim(Env::get('GCS_OBJECT_PREFIX', 'documents'), '/');
        if (!preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$#', $prefix)) {
            throw new HttpException('The cloud document prefix is invalid.', 503);
        }
        $name = $prefix . '/' . $userId . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        if (strlen($name) > 120) {
            throw new HttpException('The cloud document prefix is too long.', 503);
        }
        return $name;
    }

    public function object(
        string $name,
        ?string $bucket = null,
        ?string $generation = null,
    ): StorageObject {
        $bucket ??= Env::get('GCS_BUCKET');
        if (
            $bucket === '' ||
            !preg_match('#^[a-zA-Z0-9_/-]+\.[a-z0-9]+$#', $name) ||
            str_contains($name, '..')
        ) {
            throw new HttpException('Cloud document storage is not configured correctly.', 503);
        }
        if ($this->client === null) {
            $path = Env::get('GOOGLE_APPLICATION_CREDENTIALS');
            if ($path === '' || !is_readable($path)) {
                throw new HttpException('The cloud storage credential file is not readable.', 503);
            }
            $key = json_decode(file_get_contents($path), true);
            if (($key['type'] ?? '') !== 'service_account') {
                throw new HttpException('Cloud document storage requires a service account.', 503);
            }
            $this->client = new StorageClient([
                'projectId' => Env::get('GOOGLE_CLOUD_PROJECT'),
                'keyFilePath' => $path,
            ]);
        }
        return $this->client
            ->bucket($bucket)
            ->object($name, $generation ? ['generation' => $generation] : []);
    }

    public function remote(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new HttpException(
                (int) $error->getCode() === 404
                    ? 'The cloud document was not found.'
                    : 'Cloud document storage could not complete the request. Please try again.',
                (int) $error->getCode() === 404 ? 404 : 503,
            );
        }
    }

    public function uploadUrl(string $name, string $mime, int $size): string
    {
        return $this->remote(
            fn() => $this->object($name)->signedUrl(time() + 900, [
                'version' => 'v4',
                'method' => 'PUT',
                'contentType' => $mime,
                'headers' => [
                    'content-length' => (string) $size,
                    'x-goog-if-generation-match' => '0',
                ],
            ]),
        );
    }

    public function downloadUrl(array $document, bool $inline): string
    {
        self::assertAvailable($document);
        $disposition =
            ($inline ? 'inline' : 'attachment') .
            '; filename="document-' .
            (int) $document['id'] .
            '.' .
            pathinfo($document['storage_path'], PATHINFO_EXTENSION) .
            "\"; filename*=UTF-8''" .
            rawurlencode($document['name']);
        return $this->remote(
            fn() => $this->object(
                $document['storage_path'],
                $document['storage_bucket'],
                $document['storage_generation'],
            )->signedUrl(time() + 300, [
                'version' => 'v4',
                'responseDisposition' => $disposition,
                'responseType' => $document['mime_type'],
            ]),
        );
    }

    public function put(
        string $bytes,
        int $userId,
        string $mime,
        string $extension,
        string $storageClass = 'STANDARD',
    ): array {
        $name = $this->objectName($userId, $extension);
        $object = $this->object($name);
        return $this->remote(function () use ($object, $bytes, $mime, $storageClass): array {
            $object = $this->client->bucket(Env::get('GCS_BUCKET'))->upload($bytes, [
                'name' => $object->name(),
                'ifGenerationMatch' => 0,
                'metadata' => [
                    'storageClass' => $storageClass,
                    'contentType' => $mime,
                    'cacheControl' => 'private, no-store',
                    'contentDisposition' => 'attachment',
                ],
            ]);
            $info = $object->reload();
            if (!hash_equals(base64_encode(md5($bytes, true)), $info['md5Hash'] ?? '')) {
                throw new HttpException('The cloud document checksum did not match.', 503);
            }
            return [
                'storage_driver' => 'gcs',
                'storage_bucket' => Env::get('GCS_BUCKET'),
                'storage_path' => $object->name(),
                'storage_generation' => (string) $info['generation'],
                'storage_class' => $storageClass,
            ];
        });
    }

    public function read(array $document, bool $allowUnprocessed = false): string
    {
        self::assertAvailable($document);
        if (!$allowUnprocessed && ($document['processing_status'] ?? 'ready') !== 'ready') {
            throw new HttpException(
                'This document is still being checked or could not be read. Wait for processing or upload a valid copy.',
                409,
            );
        }
        if ((int) $document['byte_size'] > 20 * 1024 * 1024) {
            throw new HttpException('The document exceeds the processing size limit.');
        }
        $bytes = self::isCloud($document)
            ? $this->remote(
                fn() => $this->object(
                    $document['storage_path'],
                    $document['storage_bucket'],
                    $document['storage_generation'],
                )->downloadAsString(),
            )
            : file_get_contents((new DocumentService())->path($document));
        if ($bytes === false || strlen($bytes) !== (int) $document['byte_size']) {
            throw new HttpException('The document could not be read completely.', 503);
        }
        return $bytes;
    }

    public function delete(array $document): void
    {
        if (self::isCloud($document)) {
            $this->remote(
                fn() => $this->object(
                    $document['storage_path'],
                    $document['storage_bucket'],
                    $document['storage_generation'],
                )->delete(),
            );
        } elseif (!unlink((new DocumentService())->path($document))) {
            throw new HttpException('The private document could not be removed.', 503);
        }
    }
}
