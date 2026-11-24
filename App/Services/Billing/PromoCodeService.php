<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\HttpException;
use App\Repositories\PromoCodeRepository;

final class PromoCodeService
{
    public function redeem(int $userId, string $code): void
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9-]{4,64}$/D', $code)) {
            throw new HttpException('Enter a valid promo code.');
        }
        (new PromoCodeRepository())->redeemLifetime($userId, hash('sha256', $code));
    }
}
