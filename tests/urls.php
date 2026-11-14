<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Url;
use App\Http\Request;

$assertions = 0;
$originalServer = $_SERVER;
$originalUrl = getenv('APP_URL');

function expect_url(mixed $expected, mixed $actual): void
{
    global $assertions;
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true),
        );
    }
    $assertions++;
}

try {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    expect_url('', Url::basePath());
    expect_url('/login', app_url('/login'));
    expect_url('/', Url::routePath('/index.php'));
    expect_url('/api/jobs', Url::routePath('/api/jobs?sort=match'));

    $_SERVER['SCRIPT_NAME'] = '/autoaplicator/index.php';
    expect_url('/autoaplicator', Url::basePath());
    expect_url('/autoaplicator/', app_url('/'));
    expect_url('/autoaplicator/login', app_url('/login'));
    expect_url('/autoaplicator/Assets/app.css', app_url('/Assets/app.css'));
    expect_url(
        '/autoaplicator/settings?tab=ai#provider-form',
        app_url('/settings?tab=ai#provider-form'),
    );
    expect_url('/', Url::routePath('/autoaplicator/'));
    expect_url('/', Url::routePath('/autoaplicator'));
    expect_url('/', Url::routePath('/autoaplicator/index.php'));
    expect_url('/jobs/42', Url::routePath('/autoaplicator/jobs/42?view=details'));
    expect_url('/autoaplicator-other/login', Url::routePath('/autoaplicator-other/login'));
    expect_url('https://accounts.google.com/oauth', app_url('https://accounts.google.com/oauth'));
    expect_url('#main-content', app_url('#main-content'));

    $_SERVER['REQUEST_URI'] = '/autoaplicator/api/jobs';
    expect_url('/api/jobs', (new Request())->path());
    expect_url(true, (new Request())->wantsJson());

    $_SERVER['SCRIPT_NAME'] = '/nested/workspace/index.php';
    expect_url('/nested/workspace/login', app_url('/login'));
    expect_url('/login', Url::routePath('/nested/workspace/login'));

    unset($_SERVER['SCRIPT_NAME']);
    putenv('APP_URL=https://example.com/autoaplicator/');
    expect_url('/autoaplicator', Url::basePath());

    echo $assertions . ' installation URL checks passed.' . PHP_EOL;
} finally {
    $_SERVER = $originalServer;
    $originalUrl === false ? putenv('APP_URL') : putenv('APP_URL=' . $originalUrl);
}
