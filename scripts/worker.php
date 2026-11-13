<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\QueueRepository;
use App\Services\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$once = in_array('--once', $argv, true);
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void {
        $stop = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stop): void {
        $stop = true;
    });
}
$worker = new Worker();
(new QueueRepository())->recoverStale();
do {
    $worked = $worker->tick();
    if (!$worked && !$once && !$stop) {
        sleep(3);
    }
} while (!$once && !$stop);
