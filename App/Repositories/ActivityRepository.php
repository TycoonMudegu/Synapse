<?php

declare(strict_types=1);

namespace App\Repositories;

final class ActivityRepository extends Repository
{
    public function log(int $userId, string $event, string $description): void
    {
        $this->query('INSERT INTO activity_logs (user_id, event, description) VALUES (?, ?, ?)', [
            $userId,
            $event,
            mb_substr($description, 0, 500),
        ]);
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        string $href = '/activity',
    ): void {
        $this->query(
            'INSERT INTO notifications (user_id, type, title, body, href) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, $title, $body, $href],
        );
    }

    public function recent(int $userId, int $limit = 30): array
    {
        return $this->rows(
            'SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT ' .
                max(1, min(100, $limit)),
            [$userId],
        );
    }

    public function notifications(int $userId): array
    {
        return $this->rows(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100',
            [$userId],
        );
    }

    public function markRead(int $userId, int $id): void
    {
        $this->query('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?', [
            $id,
            $userId,
        ]);
    }
}
