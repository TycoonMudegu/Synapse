<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
\App\Core\Env::load(BASE_PATH . '/.env');
$testDatabase = \App\Core\Env::get('JOBFLOW_TEST_DATABASE', 'jobflow_test');
if (!str_ends_with($testDatabase, '_test') || !preg_match('/^[a-zA-Z0-9_]+$/', $testDatabase)) {
    throw new RuntimeException('Tests require a separate database whose name ends in _test.');
}
putenv('DB_DATABASE=' . $testDatabase);
// Tests supply fixture providers explicitly; never inherit the owner's live AI key.
putenv('GEMINI_API_KEY=');
// Automated tests must never deliver mail through the installation's real SMTP account.
putenv('SMTP_PASSWORD=');
putenv('DOCUMENT_RENDERER_URL=');
putenv('DOCUMENT_RENDERER_TOKEN=');
putenv('COLLECTOR_ENABLED=false');
putenv('COLLECTOR_TOKEN=');
putenv('COLLECTOR_PROJECT=');
// Never let ordinary fixtures upload documents to the owner's real bucket.
putenv('DOCUMENT_STORAGE=' . (getenv('JOBFLOW_TEST_STORAGE') ?: 'local'));
require BASE_PATH . '/Bootstrap/App.php';
// Application bootstrap hides PHP errors; surface uncaught CLI test failures in CI.
if (PHP_SAPI === 'cli') {
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, (string) $error . PHP_EOL);
        exit(1);
    });
}
