<?php

declare(strict_types=1);

use App\Core\Env;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/vendor/autoload.php';
Env::load(BASE_PATH . '/.env');
umask(0077);
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Nairobi'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php.log');

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '7200');
    session_save_path(BASE_PATH . '/storage/sessions');
    session_name('jobflow_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => \App\Core\Url::basePath() . '/',
        'secure' => Env::bool('SESSION_SECURE', true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    );
    if (Env::bool('SESSION_SECURE', true)) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
