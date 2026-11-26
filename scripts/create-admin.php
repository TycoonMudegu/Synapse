<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\Security\Validator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function admin_input(string $prompt, bool $secret = false): string
{
    fwrite(STDOUT, $prompt);
    $hidden = $secret && stream_isatty(STDIN);
    if ($hidden) {
        system('stty -echo', $status);
        if ($status !== 0) {
            throw new RuntimeException(
                'Could not hide the password. Use a terminal with stty support.',
            );
        }
    }
    try {
        $value = fgets(STDIN);
        if ($value === false) {
            throw new RuntimeException('Input ended before the administrator could be created.');
        }
        return rtrim($value, "\r\n");
    } finally {
        if ($hidden) {
            system('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }
}

try {
    // Check the role column before collecting credentials or creating an account.
    Database::connection()->query('SELECT is_admin FROM users LIMIT 0');
    $repository = new UserRepository();
    $name = Validator::text(admin_input('Full name: '), 'full name', 150, true);
    $email = Validator::email(admin_input('Email address: '), true);
    if ($repository->byEmail($email)) {
        throw new RuntimeException(
            'An account with this email already exists. Use scripts/admin.php grant to promote it.',
        );
    }
    $password = admin_input('Password (12–72 characters, hidden): ', true);
    if (strlen($password) < 12 || strlen($password) > 72) {
        throw new RuntimeException('Use a password between 12 and 72 characters.');
    }
    if ($password !== admin_input('Confirm password (hidden): ', true)) {
        throw new RuntimeException('The passwords do not match.');
    }
    Database::transaction(static function (PDO $db) use (
        $repository,
        $name,
        $email,
        $password,
    ): void {
        $id = $repository->create($name, $email, $password);
        $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$id]);
    });
    echo 'Administrator created. Sign in at /login to open the admin portal.' . PHP_EOL;
} catch (PDOException $error) {
    $message = match ($error->errorInfo[1] ?? null) {
        1054 => 'Apply the pending database migrations, including 012_job_collector.sql, first.',
        1062
            => 'An account with this email already exists. Use scripts/admin.php grant to promote it.',
        default
            => 'Administrator creation failed. Check the database configuration and pending migrations.',
    };
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
