<?php

declare(strict_types=1);

// Shared-hosting cron: one scheduler and bounded full worker under the PHP owner.
require dirname(__DIR__) . '/Bootstrap/App.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$lock = fopen(BASE_PATH . '/storage/run/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
require BASE_PATH . '/scripts/schedule.php';
$worker = new App\Services\Worker();
$started = time();
$processed = 0;
while ($processed < 20 && time() - $started < 50 && $worker->tick()) {
    $processed++;
}
