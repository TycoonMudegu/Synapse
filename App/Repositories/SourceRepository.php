<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpException;

final class SourceRepository extends Repository
{
    public function ensurePreset(int $userId, string $key, array $definition): int
    {
        $email = $definition['adapter'] === 'email_alert';
        $this->query(
            'INSERT INTO job_sources (user_id, preset_key, name, adapter, url, permission_confirmed, enabled, health) VALUES (?, ?, ?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                $userId,
                $key,
                $definition['name'],
                $definition['adapter'],
                $definition['url'],
                (int) $email,
                $email ? 'needs_connection' : 'untested',
            ],
        );
        return (int) $this->db->lastInsertId();
    }

    public function emailSources(int $userId): array
    {
        return $this->rows(
            "SELECT * FROM job_sources WHERE user_id = ? AND adapter = 'email_alert' AND preset_key IS NOT NULL AND enabled = 1 AND permission_confirmed = 1 ORDER BY id",
            [$userId],
        );
    }

    public function collectionMode(int $userId, int $id, array $definition): void
    {
        $this->get($userId, $id);
        $email = $definition['adapter'] === 'email_alert';
        $this->query(
            'UPDATE job_sources SET adapter = ?, url = ?, enabled = ?, health = ?, last_error = NULL, last_checked_at = NULL, last_success_at = NULL WHERE user_id = ? AND id = ?',
            [
                $definition['adapter'],
                $definition['url'],
                (int) $email,
                $email ? 'needs_connection' : 'untested',
                $userId,
                $id,
            ],
        );
    }

    public function emailHealth(int $userId, string $health, ?string $error = null): void
    {
        $this->query(
            "UPDATE job_sources SET health = ?, last_error = ?, last_checked_at = NOW(), last_success_at = CASE WHEN ? = 'connected' THEN NOW() ELSE last_success_at END WHERE user_id = ? AND adapter = 'email_alert'",
            [$health, $error ? mb_substr($error, 0, 500) : null, $health, $userId],
        );
    }

    public function invalidatePreset(int $userId, string $key): void
    {
        $this->query(
            "UPDATE job_sources SET enabled = 0, health = 'untested', last_error = NULL, last_checked_at = NULL WHERE user_id = ? AND preset_key = ?",
            [$userId, $key],
        );
    }

    public function all(int $userId): array
    {
        return $this->rows('SELECT * FROM job_sources WHERE user_id = ? ORDER BY id DESC', [
            $userId,
        ]);
    }

    public function enabled(int $userId, int $limit): array
    {
        return $this->rows(
            "SELECT * FROM job_sources WHERE user_id = ? AND enabled = 1 AND permission_confirmed = 1 AND health = 'connected' ORDER BY COALESCE(last_checked_at, '1970-01-01') LIMIT " .
                max(1, min(20, $limit)),
            [$userId],
        );
    }

    public function get(int $userId, int $id): array
    {
        return $this->one('SELECT * FROM job_sources WHERE user_id = ? AND id = ?', [
            $userId,
            $id,
        ]) ?? throw new HttpException('Source not found.', 404);
    }

    public function save(int $userId, array $data, int $id = 0): int
    {
        if ($id > 0) {
            $this->get($userId, $id);
            $this->query(
                "UPDATE job_sources SET name = ?, adapter = ?, url = ?, permission_confirmed = ?, enabled = 0, health = 'untested', last_error = NULL WHERE user_id = ? AND id = ?",
                [
                    $data['name'],
                    $data['adapter'],
                    $data['url'],
                    (int) $data['permission_confirmed'],
                    $userId,
                    $id,
                ],
            );
            return $id;
        }
        $this->query(
            'INSERT INTO job_sources (user_id, name, adapter, url, permission_confirmed) VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $data['name'],
                $data['adapter'],
                $data['url'],
                (int) $data['permission_confirmed'],
            ],
        );
        return (int) $this->db->lastInsertId();
    }

    public function health(int $userId, int $id, bool $healthy, ?string $error = null): void
    {
        $this->query(
            'UPDATE job_sources SET health = ?, enabled = ?, last_checked_at = NOW(), last_success_at = CASE WHEN ? = 1 THEN NOW() ELSE last_success_at END, last_error = ? WHERE user_id = ? AND id = ?',
            [
                $healthy ? 'connected' : 'error',
                (int) $healthy,
                (int) $healthy,
                $error ? mb_substr($error, 0, 500) : null,
                $userId,
                $id,
            ],
        );
    }

    public function toggle(int $userId, int $id, bool $enabled): void
    {
        $source = $this->get($userId, $id);
        if ($enabled && ($source['health'] !== 'connected' || !$source['permission_confirmed'])) {
            throw new HttpException(
                'Confirm permitted access and successfully test this source before enabling it.',
            );
        }
        $this->query('UPDATE job_sources SET enabled = ? WHERE user_id = ? AND id = ?', [
            (int) $enabled,
            $userId,
            $id,
        ]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->query('DELETE FROM job_sources WHERE user_id = ? AND id = ?', [$userId, $id]);
    }
}
