<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\DocumentRepository;
use App\Repositories\QueueRepository;
use App\Services\Security\Validator;

final class DirectUploadService
{
    public function __construct(
        private readonly DocumentStorage $storage = new DocumentStorage(),
    ) {}

    public function begin(int $userId, array $input): array
    {
        if (!DocumentStorage::enabled()) {
            throw new HttpException('Direct cloud uploads are not enabled.', 409);
        }
        $kind = Validator::choice($input['kind'] ?? '', 'document type', [
            'master_cv',
            'cover_letter_template',
            'job_poster',
            'supporting',
        ]);
        $name = basename(
            str_replace('\\', '/', Validator::text($input['name'] ?? '', 'filename', 240, true)),
        );
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime =
            [
                'pdf' => 'application/pdf',
                'docx' => RendererClient::DOCX,
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
            ][$extension] ?? '';
        if (
            $mime === '' ||
            (in_array($kind, ['master_cv', 'cover_letter_template'], true) &&
                !in_array($extension, ['pdf', 'docx'], true)) ||
            ($kind === 'job_poster' && $extension === 'docx')
        ) {
            throw new HttpException(
                'Choose a supported PDF, DOCX, JPG, PNG or WEBP file. CVs must be PDF or DOCX.',
            );
        }
        $size = filter_var($input['size'] ?? null, FILTER_VALIDATE_INT);
        $limit = min(20, max(1, (int) Env::get('UPLOAD_MAX_MB', '10'))) * 1024 * 1024;
        if ($size === false || $size <= 0 || $size > $limit) {
            throw new HttpException('Choose a non-empty document within the upload size limit.');
        }
        $db = Database::connection();
        $count = $db->prepare(
            'SELECT COUNT(*) FROM document_uploads WHERE user_id = ? AND document_id IS NULL AND expires_at > NOW()',
        );
        $count->execute([$userId]);
        if ((int) $count->fetchColumn() >= 20) {
            throw new HttpException(
                'Too many pending uploads. Finish an upload or try again in 15 minutes.',
                429,
            );
        }
        $object = $this->storage->objectName($userId, $extension);
        $url = $this->storage->uploadUrl($object, $mime, $size);
        $id = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO document_uploads (id, user_id, name, kind, storage_path, storage_bucket, mime_type, byte_size, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))',
        )->execute([$id, $userId, $name, $kind, $object, Env::get('GCS_BUCKET'), $mime, $size]);
        return [
            'upload_id' => $id,
            'url' => $url,
            'method' => 'PUT',
            'headers' => ['Content-Type' => $mime, 'x-goog-if-generation-match' => '0'],
        ];
    }

    public function complete(int $userId, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new HttpException('Upload not found.', 404);
        }
        return Database::transaction(function () use ($userId, $id): array {
            $db = Database::connection();
            $query = $db->prepare(
                'SELECT *, expires_at < NOW() AS expired FROM document_uploads WHERE id = ? AND user_id = ? FOR UPDATE',
            );
            $query->execute([$id, $userId]);
            $upload = $query->fetch();
            if (!$upload) {
                throw new HttpException('Upload not found.', 404);
            }
            $repository = new DocumentRepository();
            if ($upload['document_id']) {
                return $repository->get($userId, (int) $upload['document_id']);
            }
            if ($upload['expired']) {
                throw new HttpException('This upload expired. Choose the file again.', 409);
            }
            $object = $this->storage->object($upload['storage_path'], $upload['storage_bucket']);
            $info = $this->storage->remote(fn() => $object->info());
            if (
                (int) ($info['size'] ?? 0) !== (int) $upload['byte_size'] ||
                ($info['contentType'] ?? '') !== $upload['mime_type']
            ) {
                throw new HttpException(
                    'The uploaded file does not match the reserved document.',
                    409,
                );
            }
            // Metadata only: document bytes move directly from browser to GCS.
            $this->storage->remote(
                fn() => $object->update(
                    ['cacheControl' => 'private, no-store', 'contentDisposition' => 'attachment'],
                    ['ifGenerationMatch' => $info['generation']],
                ),
            );
            $documentId = $repository->create($userId, [
                'name' => $upload['name'],
                'kind' => $upload['kind'],
                'storage_path' => $upload['storage_path'],
                'storage_driver' => 'gcs',
                'storage_bucket' => $upload['storage_bucket'],
                'storage_generation' => (string) $info['generation'],
                'mime_type' => $upload['mime_type'],
                'byte_size' => (int) $info['size'],
                'extracted_text' => '',
                'processing_status' => 'pending',
            ]);
            if ($upload['kind'] === 'master_cv') {
                $repository->addCv($userId, $documentId);
            }
            $db->prepare('UPDATE document_uploads SET document_id = ? WHERE id = ?')->execute([
                $documentId,
                $id,
            ]);
            (new QueueRepository())->enqueue($userId, 'process_document', [
                'document_id' => $documentId,
            ]);
            return $repository->get($userId, $documentId);
        });
    }
}
