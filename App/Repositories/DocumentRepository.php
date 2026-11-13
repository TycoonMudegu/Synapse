<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

final class DocumentRepository extends Repository
{
    public function all(int $userId): array
    {
        return $this->rows(
            'SELECT d.*, COALESCE(c.is_master, 0) AS is_master FROM documents d LEFT JOIN cv_versions c ON c.document_id = d.id WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 200',
            [$userId],
        );
    }

    public function get(int $userId, int $id): array
    {
        return $this->one('SELECT * FROM documents WHERE user_id = ? AND id = ?', [$userId, $id]) ??
            throw new HttpException('Document not found.', 404);
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
            'INSERT INTO documents (user_id, name, kind, storage_path, mime_type, byte_size, extracted_text, parent_id, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
        $document = $this->get($userId, $documentId);
        if (!in_array($document['kind'], ['master_cv', 'tailored_cv'], true)) {
            throw new HttpException('Select a CV document.');
        }
        Database::transaction(function () use ($userId, $documentId): void {
            $this->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $this->query('UPDATE cv_versions SET is_master = 0 WHERE user_id = ?', [$userId]);
            $this->query(
                'UPDATE cv_versions SET is_master = 1 WHERE user_id = ? AND document_id = ?',
                [$userId, $documentId],
            );
        });
    }
}
