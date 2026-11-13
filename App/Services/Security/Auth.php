<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\UserRepository;

final class Auth
{
    private static array|false|null $cached = false;

    public static function user(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }
        $users = new UserRepository();
        if (
            isset($_SESSION['user_id']) &&
            ($_SESSION['expires_at'] ?? 0) > time() &&
            $users->validSession((int) $_SESSION['user_id'], hash('sha256', session_id()))
        ) {
            $_SESSION['expires_at'] = time() + 7200;
            return self::$cached = $users->byId((int) $_SESSION['user_id']);
        }
        unset($_SESSION['user_id']);
        $cookie = $_COOKIE['jobflow_remember'] ?? '';
        if (preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $matches)) {
            $token = $users->consumeRemember($matches[1]);
            if ($token && hash_equals($token['token_hash'], hash('sha256', $matches[2]))) {
                self::establish((int) $token['user_id']);
                self::remember((int) $token['user_id']);
                return self::$cached = $users->byId((int) $token['user_id']);
            }
            self::rememberCookie('', time() - 3600);
        }
        return self::$cached = null;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? throw new HttpException('Please sign in.', 401));
    }

    public static function login(string $email, string $password, bool $remember): bool
    {
        $users = new UserRepository();
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
        if ($users->attempts($key) >= 10) {
            throw new HttpException(
                'Too many sign-in attempts. Please try again in 15 minutes.',
                429,
            );
        }
        $users->recordAttempt($key);
        $user = $users->byEmail($email);
        $valid = password_verify(
            $password,
            $user['password_hash'] ??
                '$2y$10$8MFWCtC5hxSGXQGlZkf2KugSCTNRyBm5dGZlFwJZjZz9VKZX2sB2W',
        );
        if (!$user || !$valid) {
            return false;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $users->password((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        self::establish((int) $user['id']);
        if ($remember) {
            self::remember((int) $user['id']);
        }
        return true;
    }

    private static function establish(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['expires_at'] = time() + 7200;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        (new UserRepository())->addSession(
            $id,
            hash('sha256', session_id()),
            $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
        );
        self::$cached = false;
    }

    private static function remember(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        (new UserRepository())->remember($userId, $selector, hash('sha256', $token));
        self::rememberCookie($selector . ':' . $token, time() + 2592000);
    }

    private static function rememberCookie(string $value, int $expires): void
    {
        setcookie('jobflow_remember', $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Env::bool('SESSION_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            (new UserRepository())->revokeSessions((int) $_SESSION['user_id']);
        }
        $_SESSION = [];
        session_destroy();
        self::rememberCookie('', time() - 3600);
        self::$cached = null;
    }
}
