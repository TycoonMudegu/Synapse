<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\QueueRepository;
use App\Services\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$once = in_array('--once', $argv, true);
$drain = in_array('--drain', $argv, true);
$discoveryOnly = in_array('--discovery-only', $argv, true);
$started = time();
$processed = 0;
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
$worker = new Worker(
    $discoveryOnly ? ['discovery', 'gmail_sync', 'verify_deadlines', 'analyse_job'] : [],
);
(new QueueRepository())->recoverStale();
do {
    $worked = $worker->tick();
    $processed += (int) $worked;
    if ($drain && (!$worked || $processed >= 20 || time() - $started >= 50)) {
        break;
    }
    if (!$worked && !$once && !$stop) {
        sleep(3);
    }
} while (!$once && !$stop);
