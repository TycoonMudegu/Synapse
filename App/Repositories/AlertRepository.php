<?php

declare(strict_types=1);

namespace App\Repositories;

final class AlertRepository extends Repository
{
    public function capture(
        int $userId,
        array $source,
        int $messageId,
        string $body,
        array $urls,
    ): void {
        $owned = $this->one(
            'SELECT s.id FROM job_sources s JOIN application_correspondence m ON m.user_id = s.user_id WHERE s.user_id = ? AND s.id = ? AND m.id = ?',
            [$userId, $source['id'], $messageId],
        );
        if (!$owned) {
            throw new \App\Core\HttpException('Alert source or message not found.', 404);
        }
        foreach ($urls as $url) {
            $this->query(
                'INSERT IGNORE INTO source_alert_items (user_id, source_id, correspondence_id, original_url, url_hash, advertisement) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $source['id'],
                    $messageId,
                    $url,
                    hash('sha256', $url),
                    mb_substr($body, 0, 60000),
                ],
            );
        }
    }

    public function pending(int $userId, int $sourceId, int $limit): array
    {
        return $this->rows(
            "SELECT * FROM source_alert_items WHERE user_id = ? AND source_id = ? AND status = 'pending' ORDER BY id LIMIT " .
                max(1, min(200, $limit)),
            [$userId, $sourceId],
        );
    }

    public function finish(
        int $userId,
        int $id,
        string $status,
        ?int $jobId = null,
        ?string $error = null,
    ): void {
        $this->query(
            'UPDATE source_alert_items SET status = ?, job_id = ?, last_error = ?, processed_at = NOW() WHERE user_id = ? AND id = ?',
            [$status, $jobId, $error ? mb_substr($error, 0, 500) : null, $userId, $id],
        );
    }

    public function retry(int $userId, int $sourceId): int
    {
        return $this->query(
            "UPDATE source_alert_items SET status = 'pending', last_error = NULL WHERE user_id = ? AND source_id = ? AND status IN ('error', 'needs_review')",
            [$userId, $sourceId],
        )->rowCount();
    }

    public function counts(int $userId): array
    {
        return $this->rows(
            'SELECT source_id, status, COUNT(*) AS total FROM source_alert_items WHERE user_id = ? GROUP BY source_id, status',
            [$userId],
        );
    }
}
