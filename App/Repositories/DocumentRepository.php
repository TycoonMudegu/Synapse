<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

final class DocumentRepository extends Repository
{
    public function all(int $userId, bool $favoritesOnly = false): array
    {
        return $this->rows(
            'SELECT d.*, COALESCE(c.is_master, 0) AS is_master FROM documents d LEFT JOIN cv_versions c ON c.document_id = d.id WHERE d.user_id = ?' .
                ($favoritesOnly ? ' AND d.is_favorite = 1' : '') .
                ' ORDER BY d.archived_at IS NOT NULL, d.id DESC LIMIT 200',
            [$userId],
        );
    }

    public function get(int $userId, int $id): array
    {
        return $this->one('SELECT * FROM documents WHERE user_id = ? AND id = ?', [$userId, $id]) ??
            throw new HttpException('Document not found.', 404);
    }

    public function archiveCandidates(int $userId, ?int $documentId = null): array
    {
        // Every application sharing an attachment must have a confirmed submission
        // at least three days old. Drafts and ambiguous sends keep it available.
        return $this->rows(
            "SELECT d.* FROM documents d
             WHERE d.user_id = ? AND d.is_favorite = 0 AND d.archived_at IS NULL
               AND d.processing_status = 'ready'
               AND d.kind NOT IN ('master_cv', 'cover_letter_template')
               AND NOT EXISTS (SELECT 1 FROM cv_versions c WHERE c.user_id = d.user_id AND c.document_id = d.id AND c.is_master = 1)
               AND NOT EXISTS (SELECT 1 FROM onboarding_preferences p WHERE p.user_id = d.user_id AND (p.cv_document_id = d.id OR p.letter_document_id = d.id))
               AND EXISTS (SELECT 1 FROM applications a WHERE a.user_id = d.user_id AND (a.cv_document_id = d.id OR a.letter_document_id = d.id))
               AND NOT EXISTS (
                   SELECT 1 FROM applications a WHERE a.user_id = d.user_id
                     AND (a.cv_document_id = d.id OR a.letter_document_id = d.id)
                     AND (a.submitted_at IS NULL OR a.submitted_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
                          OR a.confirmation IS NULL OR TRIM(a.confirmation) = ''
                          OR a.status NOT IN ('submitted', 'interview', 'offer', 'rejected', 'withdrawn'))
               )" .
                ($documentId === null ? '' : ' AND d.id = ?') .
                ' ORDER BY d.id LIMIT 100',
            $documentId === null ? [$userId] : [$userId, $documentId],
        );
    }

    public function favorite(int $userId, int $id, bool $favorite): void
    {
        $this->get($userId, $id);
        $this->query('UPDATE documents SET is_favorite = ? WHERE user_id = ? AND id = ?', [
            (int) $favorite,
            $userId,
            $id,
        ]);
    }

    public function storage(int $userId, int $id, array $storage, ?string $archivedAt): void
    {
        $this->query(
            'UPDATE documents SET storage_driver = ?, storage_bucket = ?, storage_path = ?, storage_generation = ?, storage_class = ?, archived_at = ? WHERE user_id = ? AND id = ?',
            [
                $storage['storage_driver'],
                $storage['storage_bucket'],
                $storage['storage_path'],
                $storage['storage_generation'],
                $storage['storage_class'],
                $archivedAt,
                $userId,
                $id,
            ],
        );
    }

    public function associations(int $userId, int $id): array
    {
        return $this->rows(
            'SELECT DISTINCT j.id, j.title, j.employer FROM job_listings j LEFT JOIN applications a ON a.job_id = j.id AND a.user_id = j.user_id WHERE j.user_id = ? AND (j.poster_document_id = ? OR a.cv_document_id = ? OR a.letter_document_id = ?)',
            [$userId, $id, $id, $id],
        );
    }

    public function nextVersions(int $userId, int $id): array
    {
        return $this->rows(
            'SELECT id, name, version, created_at FROM documents WHERE user_id = ? AND parent_id = ? ORDER BY id DESC',
            [$userId, $id],
        );
    }

    public function cvSnapshot(int $userId, int $id): ?array
    {
        $row = $this->one(
            'SELECT profile_json FROM cv_versions WHERE user_id = ? AND document_id = ?',
            [$userId, $id],
        );
        return $row && $row['profile_json']
            ? json_decode($row['profile_json'], true, 64, JSON_THROW_ON_ERROR)
            : null;
    }

    public function create(int $userId, array $data): int
    {
        $this->query(
            'INSERT INTO documents (user_id, name, kind, storage_path, mime_type, byte_size, extracted_text, parent_id, version, storage_driver, storage_bucket, storage_generation, processing_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['name'],
                $data['kind'],
                $data['storage_path'],
                $data['mime_type'],
                $data['byte_size'],
                $data['extracted_text'] ?? null,
                $data['parent_id'] ?? null,
                $data['version'] ?? 1,
                $data['storage_driver'] ?? 'local',
                $data['storage_bucket'] ?? null,
                $data['storage_generation'] ?? null,
                $data['processing_status'] ?? 'ready',
            ],
        );
        return (int) $this->db->lastInsertId();
    }

    public function updateText(int $userId, int $id, string $text): void
    {
        $this->query('UPDATE documents SET extracted_text = ? WHERE user_id = ? AND id = ?', [
            $text,
            $userId,
            $id,
        ]);
    }

    public function rename(int $userId, int $id, string $name): void
    {
        $this->get($userId, $id);
        $this->query('UPDATE documents SET name = ? WHERE user_id = ? AND id = ?', [
            $name,
            $userId,
            $id,
        ]);
    }

    public function delete(int $userId, int $id): array
    {
        $document = $this->get($userId, $id);
        $used = $this->one(
            'SELECT id FROM applications WHERE user_id = ? AND (cv_document_id = ? OR letter_document_id = ?) LIMIT 1',
            [$userId, $id, $id],
        );
        if ($used) {
            throw new HttpException(
                'This document is attached to an application. Retain it as part of the application record.',
            );
        }
        $this->query('DELETE FROM documents WHERE user_id = ? AND id = ?', [$userId, $id]);
        return $document;
    }

    public function addCv(int $userId, int $documentId, ?array $profile = null): void
    {
        $this->get($userId, $documentId);
        $this->query(
            'INSERT INTO cv_versions (user_id, document_id, profile_json) VALUES (?, ?, ?)',
            [$userId, $documentId, $profile ? json_encode($profile, JSON_THROW_ON_ERROR) : null],
        );
    }

    public function master(int $userId, int $documentId): void
    {
        Database::transaction(function () use ($userId, $documentId): void {
            $this->lockUser($userId);
            $document = $this->get($userId, $documentId);
            \App\Services\Documents\DocumentStorage::assertAvailable($document);
            if (!in_array($document['kind'], ['master_cv', 'tailored_cv'], true)) {
                throw new HttpException('Select a CV document.');
            }
            $this->query('UPDATE cv_versions SET is_master = 0 WHERE user_id = ?', [$userId]);
            $this->query(
                'UPDATE cv_versions SET is_master = 1 WHERE user_id = ? AND document_id = ?',
                [$userId, $documentId],
            );
        });
    }
}
