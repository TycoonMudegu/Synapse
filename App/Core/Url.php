<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with($script, '/index.php')) {
            return self::normalise(substr($script, 0, -strlen('/index.php')));
        }

        // CLI notifications and scheduled work use the configured installation URL.
        if (PHP_SAPI === 'cli') {
            return self::normalise(parse_url(Env::get('APP_URL'), PHP_URL_PATH) ?: '');
        }

        return '';
    }

    public static function to(string $path): string
    {
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '#')) {
            return $path;
        }

        if (str_starts_with($path, '//')) {
            throw new \InvalidArgumentException(
                'Protocol-relative application URLs are not supported.',
            );
        }

        return self::basePath() . '/' . ltrim($path, '/');
    }

    public static function routePath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && $path === $base) {
            return '/';
        }
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if ($path === '/index.php') {
            return '/';
        }

        return $path;
    }

    private static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');
        if (preg_match('/[\x00-\x20\x7f?#]/', $path)) {
            throw new \InvalidArgumentException('The installation path is invalid.');
        }
        return $path === '/' ? '' : $path;
    }
}
