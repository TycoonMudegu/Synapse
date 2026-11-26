<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\HttpException;

final class Admin
{
    public static function allowed(?array $user): bool
    {
        return $user !== null && (int) ($user['is_admin'] ?? 0) === 1;
    }

    public static function require(): int
    {
        if (!self::allowed(Auth::user())) {
            throw new HttpException('Administrator access is required.', 403);
        }
        return Auth::id();
    }
}
