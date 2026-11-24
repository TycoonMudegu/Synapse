<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\DocumentRepository;

final class DocumentRetentionService
{
    public function __construct(
        private readonly DocumentStorage $storage = new DocumentStorage(),
    ) {}

    public function archive(int $userId, int $documentId): bool
    {
        if (!DocumentStorage::enabled()) {
            return false;
        }
        $localPath = null;
        $archived = Database::transaction(function () use (
            $userId,
            $documentId,
            &$localPath,
        ): bool {
            $documents = new DocumentRepository();
            $documents->lockUser($userId);
            $document = $documents->archiveCandidates($userId, $documentId)[0] ?? null;
            if (!$document) {
                return false;
            }
            $class = Env::get('GCS_COLD_STORAGE_CLASS', 'COLDLINE');
            if (!in_array($class, ['NEARLINE', 'COLDLINE', 'ARCHIVE'], true)) {
                throw new HttpException('Configure a valid cold storage class.', 503);
            }
            if (DocumentStorage::isCloud($document)) {
                $storage = $this->storage->changeClass($document, $class);
            } else {
                $localPath = (new DocumentService())->path($document);
                $storage = $this->storage->put(
                    $this->storage->read($document),
                    $userId,
                    $document['mime_type'],
                    pathinfo($document['storage_path'], PATHINFO_EXTENSION),
                    $class,
                );
            }
            $documents->storage($userId, $documentId, $storage, date('Y-m-d H:i:s'));
            (new ActivityRepository())->log(
                $userId,
                'document.archived',
                'Moved document #' . $documentId . ' to cold storage.',
            );
            return true;
        });
        // Remove a local original only after the verified cloud reference is committed.
        if ($archived && $localPath !== null && !unlink($localPath)) {
            error_log('Unable to remove migrated local document #' . $documentId . '.');
        }
        return $archived;
    }

    public function favorite(int $userId, int $documentId, bool $favorite): void
    {
        Database::transaction(function () use ($userId, $documentId, $favorite): void {
            $documents = new DocumentRepository();
            $documents->lockUser($userId);
            $document = $documents->get($userId, $documentId);
            // Reconcile a rewrite interrupted before archived_at was committed,
            // so favoriting cannot strand the previous cloud generation.
            if ($favorite && DocumentStorage::isCloud($document)) {
                $storage = $this->storage->changeClass($document, 'STANDARD');
                $documents->storage($userId, $documentId, $storage, null);
            }
            $documents->favorite($userId, $documentId, $favorite);
        });
    }
}
