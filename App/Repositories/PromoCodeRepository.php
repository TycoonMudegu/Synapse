<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

final class PromoCodeRepository extends Repository
{
    public function redeemLifetime(int $userId, string $codeHash): void
    {
        Database::transaction(function () use ($userId, $codeHash): void {
            // Use the same user lock as M-Pesa settlement to preserve lifetime access.
            $this->lockUser($userId);
            $promo = $this->one(
                'SELECT * FROM promo_codes WHERE code_hash = ? AND enabled = 1 AND (expires_at IS NULL OR expires_at > NOW()) FOR UPDATE',
                [$codeHash],
            );
            if (!$promo) {
                throw new HttpException('This promo code is invalid or is no longer available.');
            }
            $existing = $this->one(
                'SELECT user_id FROM promo_redemptions WHERE promo_code_id = ? AND user_id = ?',
                [$promo['id'], $userId],
            );
            if (!$existing && $promo['max_redemptions'] !== null) {
                $count = (int) $this->query(
                    'SELECT COUNT(*) FROM promo_redemptions WHERE promo_code_id = ?',
                    [$promo['id']],
                )->fetchColumn();
                if ($count >= (int) $promo['max_redemptions']) {
                    throw new HttpException(
                        'This promo code is invalid or is no longer available.',
                    );
                }
            }
            $this->query(
                "INSERT INTO memberships (user_id, plan, expires_at) VALUES (?, 'lifetime', NULL) ON DUPLICATE KEY UPDATE plan = 'lifetime', expires_at = NULL",
                [$userId],
            );
            if ($existing) {
                return;
            }
            $this->query('INSERT INTO promo_redemptions (promo_code_id, user_id) VALUES (?, ?)', [
                $promo['id'],
                $userId,
            ]);
            (new ActivityRepository())->log(
                $userId,
                'billing.promo_redeemed',
                'Lifetime Synapse Plus activated with a promo code. No payment required.',
            );
        });
    }
}
