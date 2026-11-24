<?php

declare(strict_types=1);

use App\Core\Env;

// Deployment always reads the selected hosting file, without application sessions.
return static function (string $file): array {
    if (!is_readable($file)) {
        throw new RuntimeException('The selected database environment file is not readable.');
    }
    $env = Env::parse(file_get_contents($file));
    $config = [
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($env['DB_PORT'] ?? '3306'),
        'name' => $env['DB_DATABASE'] ?? '',
        'user' => $env['DB_USERNAME'] ?? '',
        'pass' => $env['DB_PASSWORD'] ?? '',
        'socket' => $env['DB_SOCKET'] ?? '',
    ];
    if (
        $config['name'] === '' ||
        $config['user'] === '' ||
        $config['port'] < 1 ||
        $config['port'] > 65535
    ) {
        throw new RuntimeException(
            'Set DB_DATABASE, DB_USERNAME and a valid DB_PORT in the selected environment file.',
        );
    }
    foreach (['host', 'name', 'socket'] as $part) {
        if (strpbrk($config[$part], ";\r\n\0") !== false) {
            throw new RuntimeException('Invalid database connection setting.');
        }
    }
    return $config;
};
