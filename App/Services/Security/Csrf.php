<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\HttpException;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validate(string $token): void
    {
        if ($token === '' || !hash_equals(self::token(), $token)) {
            throw new HttpException(
                'Your session has expired. Refresh this page and try again.',
                403,
            );
        }
    }
}
