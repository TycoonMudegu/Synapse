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
require BASE_PATH . '/Bootstrap/App.php';
