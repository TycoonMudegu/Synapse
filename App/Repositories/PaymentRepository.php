<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

final class PaymentRepository extends Repository
{
    public function create(
        int $userId,
        string $plan,
        int $amount,
        string $phone,
        string $environment,
        string $callbackToken,
    ): int {
        return Database::transaction(function () use (
            $userId,
            $plan,
            $amount,
            $phone,
            $environment,
            $callbackToken,
        ): int {
            $this->lockUser($userId);
            if (
                $this->one(
                    "SELECT id FROM payments WHERE user_id = ? AND status IN ('initiating', 'pending') AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                    [$userId],
                )
            ) {
                throw new HttpException(
                    'A payment request is already in progress. Open Plans to check it before trying again.',
                    429,
                );
            }
            $this->query(
                'INSERT INTO payments (user_id, plan, amount, phone, environment, callback_hash) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $plan, $amount, $phone, $environment, hash('sha256', $callbackToken)],
            );
            return (int) $this->db->lastInsertId();
        });
    }

    public function started(int $id, string $checkout, string $merchant): void
    {
        $this->query(
            "UPDATE payments SET status = 'pending', checkout_id = ?, merchant_id = ? WHERE id = ? AND status = 'initiating'",
            [$checkout, $merchant, $id],
        );
    }

    public function failed(int $id): void
    {
        $this->query(
            "UPDATE payments SET status = 'unconfirmed' WHERE id = ? AND status = 'initiating'",
            [$id],
        );
    }

    public function get(int $userId, int $id): array
    {
        return $this->one('SELECT * FROM payments WHERE user_id = ? AND id = ?', [$userId, $id]) ??
            throw new HttpException('Payment not found.', 404);
    }

    public function latest(int $userId): ?array
    {
        return $this->one('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 1', [
            $userId,
        ]);
    }

    public function byCheckout(string $checkout): ?array
    {
        return $this->one('SELECT * FROM payments WHERE checkout_id = ?', [$checkout]);
    }

    public function claimCheck(int $id): bool
    {
        return $this->query(
            "UPDATE payments SET last_checked_at = NOW() WHERE id = ? AND status = 'pending' AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL 15 SECOND))",
            [$id],
        )->rowCount() > 0;
    }

    public function settle(int $id, string $resultCode): void
    {
        Database::transaction(function () use ($id, $resultCode): void {
            $row = $this->one('SELECT * FROM payments WHERE id = ?', [$id]);
            if (!$row) {
                return;
            }
            $this->lockUser((int) $row['user_id']);
            $row = $this->one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
            if ($row['status'] !== 'pending') {
                return;
            }
            $success = $resultCode === '0';
            $this->query(
                'UPDATE payments SET status = ?, result_code = ?, paid_at = ? WHERE id = ?',
                [
                    $success ? 'paid' : 'failed',
                    $resultCode,
                    $success ? date('Y-m-d H:i:s') : null,
                    $id,
                ],
            );
            if (!$success) {
                return;
            }
            // Sandbox requests never grant production access.
            if (
                $row['environment'] !== 'production' &&
                \App\Core\Env::get('APP_ENV', 'production') === 'production'
            ) {
                throw new HttpException('Sandbox payments cannot unlock a production account.');
            }
            // Read current committed membership after the user lock, even when this
            // transaction waited behind another successful renewal.
            $current = (new AccountRepository())->membership((int) $row['user_id'], true);
            if ($current['plan'] === 'lifetime') {
                return;
            }
            $expires = null;
            if ($row['plan'] === 'monthly') {
                $start =
                    $current['active'] && $current['expires_at']
                        ? new \DateTimeImmutable($current['expires_at'])
                        : new \DateTimeImmutable();
                // Calendar month, clamped at the last day of the destination month.
                $next = $start->modify('first day of next month');
                $expires = $next
                    ->setDate(
                        (int) $next->format('Y'),
                        (int) $next->format('m'),
                        min((int) $start->format('d'), (int) $next->format('t')),
                    )
                    ->format('Y-m-d H:i:s');
            }
            $this->query(
                'INSERT INTO memberships (user_id, plan, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE plan = VALUES(plan), expires_at = VALUES(expires_at)',
                [$row['user_id'], $row['plan'], $expires],
            );
            (new ActivityRepository())->log(
                (int) $row['user_id'],
                'billing.paid',
                'M-Pesa payment verified. Synapse Plus activated.',
            );
        });
    }
}
