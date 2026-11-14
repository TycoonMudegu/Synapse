<?php

declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file, __DIR__ . '/Assets/') && is_file($file)) {
    return false;
}
// The development server otherwise reports a virtual route as SCRIPT_NAME.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
