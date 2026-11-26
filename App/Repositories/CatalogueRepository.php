<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

final class CatalogueRepository extends Repository
{
    public function get(int $id, bool $publicOnly = false): array
    {
        $row = $this->one(
            'SELECT j.*, s.name AS source_name FROM shared_jobs j LEFT JOIN collector_sources s ON s.id = j.source_id WHERE j.id = ?' .
                ($publicOnly ? ' AND j.published = 1' : ''),
            [$id],
        );
        if (!$row) {
            throw new HttpException('Opportunity not found.', 404);
        }
        return [...$row, 'job' => json_decode($row['job_json'], true, 64, JSON_THROW_ON_ERROR)];
    }

    public function list(string $query = '', bool $admin = false, int $page = 1): array
    {
        $where = $admin
            ? '1 = 1'
            : "j.published = 1 AND (JSON_UNQUOTE(JSON_EXTRACT(j.job_json, '$.deadline')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(j.job_json, '$.deadline')) = 'null' OR JSON_UNQUOTE(JSON_EXTRACT(j.job_json, '$.deadline')) >= CURDATE())";
        $parameters = [];
        if ($query !== '') {
            $where .=
                ' AND (JSON_UNQUOTE(JSON_EXTRACT(j.job_json, \'$.title\')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(j.job_json, \'$.employer\')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(j.job_json, \'$.location\')) LIKE ?)';
            $parameters = array_fill(0, 3, '%' . mb_substr($query, 0, 150) . '%');
        }
        return array_map(
            static fn(array $row): array => [
                ...$row,
                'job' => json_decode($row['job_json'], true, 64, JSON_THROW_ON_ERROR),
            ],
            $this->rows(
                'SELECT j.*, s.name AS source_name FROM shared_jobs j LEFT JOIN collector_sources s ON s.id = j.source_id WHERE ' .
                    $where .
                    ' ORDER BY j.id DESC LIMIT 30 OFFSET ' .
                    (max(1, min(10000, $page)) - 1) * 30,
                $parameters,
            ),
        );
    }

    /** The caller holds the collector/admin transaction. Publication and editorial overrides survive refreshes. */
    public function upsert(
        array $job,
        ?int $sourceId,
        bool $editor = false,
        ?int $id = null,
        ?int $revision = null,
        bool $published = true,
    ): array {
        $existing =
            $id !== null
                ? $this->one('SELECT * FROM shared_jobs WHERE id = ? FOR UPDATE', [$id])
                : $this->one(
                    'SELECT * FROM shared_jobs WHERE canonical_hash = ? OR fingerprint = ? ORDER BY (canonical_hash = ?) DESC LIMIT 1 FOR UPDATE',
                    [$job['canonical_hash'], $job['fingerprint'], $job['canonical_hash']],
                );
        if ($id !== null && !$existing) {
            throw new HttpException('Opportunity not found.', 404);
        }
        if ($editor && $id === null && $existing) {
            throw new HttpException(
                'This vacancy is already in the catalogue. Edit its existing entry.',
                409,
            );
        }
        if (
            $existing &&
            $editor &&
            $revision !== null &&
            (int) $existing['revision'] !== $revision
        ) {
            throw new HttpException('This opportunity changed. Reload before saving.', 409);
        }
        $payload = $job;
        unset(
            $payload['source_id'],
            $payload['poster_document_id'],
            $payload['verified_at'],
            $payload['canonical_hash'],
            $payload['fingerprint'],
        );
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($existing) {
            if (!$editor && $existing['editor_locked']) {
                $this->query('UPDATE shared_jobs SET last_seen_at = NOW() WHERE id = ?', [
                    $existing['id'],
                ]);
                return ['id' => (int) $existing['id'], 'inserted' => 0, 'updated' => 0];
            }
            $this->query(
                'UPDATE shared_jobs SET job_json = ?, canonical_hash = ?, fingerprint = ?, published = ?, editor_locked = ?, revision = revision + 1, last_seen_at = NOW() WHERE id = ?',
                [
                    $json,
                    $job['canonical_hash'],
                    $job['fingerprint'],
                    $editor ? (int) $published : (int) $existing['published'],
                    $editor ? 1 : 0,
                    $existing['id'],
                ],
            );
            return ['id' => (int) $existing['id'], 'inserted' => 0, 'updated' => 1];
        }
        $this->query(
            'INSERT INTO shared_jobs (source_id, job_json, canonical_hash, fingerprint, published, editor_locked) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $sourceId,
                $json,
                $job['canonical_hash'],
                $job['fingerprint'],
                (int) $published,
                (int) $editor,
            ],
        );
        return ['id' => (int) $this->db->lastInsertId(), 'inserted' => 1, 'updated' => 0];
    }

    public function publish(int $id, bool $published): void
    {
        $this->get($id);
        $this->query('UPDATE shared_jobs SET published = ?, revision = revision + 1 WHERE id = ?', [
            (int) $published,
            $id,
        ]);
    }

    public function counts(): array
    {
        return $this->one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(published = 1), 0) AS published FROM shared_jobs',
        ) ?? ['total' => 0, 'published' => 0];
    }

    public function saveForUser(int $id, int $userId): array
    {
        $row = $this->get($id, true);
        if (!empty($row['job']['deadline']) && $row['job']['deadline'] < date('Y-m-d')) {
            throw new HttpException('This vacancy has expired.');
        }
        $result = (new \App\Services\JobService())->create($userId, [
            ...$row['job'],
            'verified' => false,
        ]);
        $this->query(
            'UPDATE job_listings SET catalogue_job_id = COALESCE(catalogue_job_id, ?) WHERE user_id = ? AND id = ?',
            [$id, $userId, $result['id']],
        );
        return $result;
    }
}
