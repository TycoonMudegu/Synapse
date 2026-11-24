<?php

declare(strict_types=1);

// Standalone deployment package: no application vendor tree or HTTP bootstrap.
spl_autoload_register(static function (string $class): void {
    if (preg_match('/^App\\\\(?:Database\\\\[A-Za-z]+|Core\\\\Env)$/D', $class)) {
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
