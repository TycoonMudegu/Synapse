<?php

declare(strict_types=1);

// Packaged as _db-deploy/releases/<commit>/endpoint.php. Never runs application code.
ini_set('display_errors', '0');
umask(0077);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/App/Database/HttpDeployment.php';
ob_start();
$response = App\Database\HttpDeployment::handle(
    __DIR__,
    dirname(__DIR__, 3) . '/.env',
    $_SERVER,
    file_get_contents('php://input'),
);
ob_end_clean();
http_response_code($response['code']);
echo json_encode($response['body'], JSON_THROW_ON_ERROR);
