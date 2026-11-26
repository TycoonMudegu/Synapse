<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class QueueRepository extends Repository
{
    public function enqueue(
        int $userId,
        string $type,
        array $payload = [],
        ?string $key = null,
    ): int {
        $dedup = hash(
            'sha256',
            $userId . '|' . $type . '|' . ($key ?? json_encode($payload, JSON_THROW_ON_ERROR)),
        );
        $this->query(
            'INSERT INTO background_tasks (user_id, type, payload_json, dedup_key, max_attempts) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                $userId,
                $type,
                json_encode($payload, JSON_THROW_ON_ERROR),
                $dedup,
                in_array($type, ['submit_application', 'manual_job_alert'], true) ? 1 : 3,
            ],
        );
        return (int) $this->db->lastInsertId();
    }

    public function claim(array $types = []): ?array
    {
        return Database::transaction(function () use ($types): ?array {
            $filter = $types
                ? ' AND type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')'
                : '';
            $task = $this->one(
                "SELECT * FROM background_tasks WHERE status = 'pending' AND available_at <= NOW()" .
                    $filter .
                    ' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED',
                $types,
            );
            if (!$task) {
                return null;
            }
            $token = bin2hex(random_bytes(24));
            $this->query(
                "UPDATE background_tasks SET status = 'running', attempts = attempts + 1, reserved_at = NOW(), reservation_token = ? WHERE id = ?",
                [$token, $task['id']],
            );
            $task['reservation_token'] = $token;
            $task['attempts']++;
            return $task;
        });
    }

    public function complete(array $task): void
    {
        $this->query(
            "UPDATE background_tasks SET status = 'completed', completed_at = NOW(), dedup_key = NULL WHERE id = ? AND reservation_token = ?",
            [$task['id'], $task['reservation_token']],
        );
    }

    public function fail(array $task, string $message): void
    {
        $retry = $task['attempts'] < $task['max_attempts'];
        $this->query(
            'UPDATE background_tasks SET status = ?, last_error = ?, dedup_key = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ? AND reservation_token = ?',
            [
                $retry ? 'pending' : 'failed',
                mb_substr($message, 0, 500),
                $retry ? $task['dedup_key'] : null,
                min(3600, 60 * 2 ** (int) $task['attempts']),
                $task['id'],
                $task['reservation_token'],
            ],
        );
    }

    public function recoverStale(): int
    {
        // SMTP/API sends cannot safely be replayed after an ambiguous crash. Require reconciliation.
        $this->query(
            "UPDATE manual_job_alerts n JOIN background_tasks t ON t.user_id = n.user_id AND CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json, '$.alert_id')) AS UNSIGNED) = n.id SET n.status = 'failed', n.last_error = 'Worker interrupted during email delivery. Check the sender mailbox before retrying.' WHERE t.type = 'manual_job_alert' AND t.status = 'running' AND t.reserved_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND n.status = 'sending'",
        );
        $this->query(
            "UPDATE applications a JOIN background_tasks t ON t.user_id = a.user_id AND CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json, '$.application_id')) AS UNSIGNED) = a.id SET a.status = 'awaiting_external_action', a.last_error = 'Worker interrupted during submission. Check Gmail Sent before taking further action.' WHERE t.type = 'submit_application' AND t.status = 'running' AND t.reserved_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND a.status = 'submitting'",
        );
        $failed = $this->query(
            "UPDATE background_tasks SET status = 'failed', dedup_key = NULL, last_error = 'Worker lease expired. Review delivery before retrying.' WHERE status = 'running' AND reserved_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (type IN ('submit_application', 'manual_job_alert') OR attempts >= max_attempts)",
        )->rowCount();
        return $failed +
            $this->query(
                "UPDATE background_tasks SET status = 'pending', reservation_token = NULL, available_at = NOW() WHERE status = 'running' AND reserved_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND type NOT IN ('submit_application', 'manual_job_alert') AND attempts < max_attempts",
            )->rowCount();
    }

    public function get(int $userId, int $id): array
    {
        return $this->one(
            'SELECT id, type, status, attempts, last_error, created_at, completed_at FROM background_tasks WHERE user_id = ? AND id = ?',
            [$userId, $id],
        ) ?? throw new \App\Core\HttpException('Task not found.', 404);
    }

    public function defer(array $task): void
    {
        $this->query(
            "UPDATE background_tasks SET status = 'pending', attempts = GREATEST(0, attempts - 1), available_at = DATE_ADD(NOW(), INTERVAL 60 SECOND), reservation_token = NULL WHERE id = ? AND reservation_token = ?",
            [$task['id'], $task['reservation_token']],
        );
    }

    public function recent(int $userId): array
    {
        return $this->rows(
            'SELECT id, type, status, attempts, last_error, created_at, completed_at FROM background_tasks WHERE user_id = ? ORDER BY id DESC LIMIT 30',
            [$userId],
        );
    }

    public function runs(int $userId): array
    {
        return $this->rows(
            'SELECT * FROM automation_runs WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [$userId],
        );
    }

    public function startRun(int $userId, string $type): int
    {
        $this->query('INSERT INTO automation_runs (user_id, task_type) VALUES (?, ?)', [
            $userId,
            $type,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function endRun(
        int $id,
        string $status,
        int $records,
        int $sources = 0,
        ?string $error = null,
    ): void {
        $this->query(
            'UPDATE automation_runs SET status = ?, records_processed = ?, sources_checked = ?, error_summary = ?, completed_at = NOW() WHERE id = ?',
            [$status, $records, $sources, $error ? mb_substr($error, 0, 500) : null, $id],
        );
    }

    public function pruneTransientRecords(): void
    {
        $this->query('DELETE FROM request_limits WHERE expires_at < NOW()');
        $this->query(
            'DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)',
        );
        $this->query('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    }

    public function users(): array
    {
        return $this->rows('SELECT id FROM users WHERE is_admin = 0 ORDER BY id');
    }

    public function latestRun(int $userId, string $type): ?array
    {
        return $this->one(
            'SELECT * FROM automation_runs WHERE user_id = ? AND task_type = ? ORDER BY id DESC LIMIT 1',
            [$userId, $type],
        );
    }

    public function lock(string $name): bool
    {
        return (int) $this->query('SELECT GET_LOCK(?, 0)', [$name])->fetchColumn() === 1;
    }

    public function unlock(string $name): void
    {
        $this->query('SELECT RELEASE_LOCK(?)', [$name]);
    }
}
