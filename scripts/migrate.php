<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Env;
use App\Database\Runner;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$runner = new Runner(BASE_PATH, [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_DATABASE', 'jobflow'),
    'user' => Env::get('DB_USERNAME', 'jobflow'),
    'pass' => Env::get('DB_PASSWORD'),
    'socket' => Env::get('DB_SOCKET'),
]);
$retry = in_array('--retry-failed', $argv ?? [], true);
$runner->locked(static function () use ($runner, $retry): void {
    $previous = $runner->history();
    do {
        $history = $runner->next($retry);
        foreach (array_diff($history['applied'], $previous['applied']) as $name) {
            echo 'Applied ' . $name . PHP_EOL;
        }
        $previous = $history;
    } while ($history['pending']);
    echo 'Database is up to date.' . PHP_EOL;
});
