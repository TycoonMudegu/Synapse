<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpException;

final class RateLimitRepository extends Repository
{
    public function check(int $userId, string $group, int $limit, int $window = 60): void
    {
        $bucket = intdiv(time(), $window);
        $key = hash('sha256', $userId . '|' . $group . '|' . $bucket);
        $this->query(
            'INSERT INTO request_limits (bucket_key, hits, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, date('Y-m-d H:i:s', ($bucket + 1) * $window)],
        );
        $hits = (int) $this->query('SELECT hits FROM request_limits WHERE bucket_key = ?', [
            $key,
        ])->fetchColumn();
        if ($hits > $limit) {
            header('Retry-After: ' . max(1, ($bucket + 1) * $window - time()));
            throw new HttpException(
                'Too many requests. Please wait a moment before trying again.',
                429,
            );
        }
    }
}
