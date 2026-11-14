<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Repositories\UserRepository;
use App\Services\Discovery\DiscoveryService;
use App\Services\Discovery\SourcePresetService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$userId = isset($argv[1]) ? (int) $argv[1] : 0;
if (!(new UserRepository())->byId($userId)) {
    fwrite(STDERR, "Usage: php scripts/add-job-sources.php <existing-user-id>\n");
    exit(1);
}
$ids = (new SourcePresetService())->install($userId);
$sources = new SourceRepository();
$source = $sources->get($userId, $ids['myjobmag']);
try {
    (new DiscoveryService())->adapter('myjobmag')->validate($source);
    $sources->health($userId, $source['id'], true);
    echo "MyJobMag: official detailed RSS connection verified.\n";
} catch (Throwable $exception) {
    $message =
        $exception instanceof \App\Core\HttpException
            ? $exception->getMessage()
            : 'Feed validation failed.';
    $sources->health($userId, $source['id'], false, $message);
    echo 'MyJobMag: ' . $message . PHP_EOL;
}
$repository = new SettingsRepository();
$automation = $repository->get($userId, 'automation');
$automation['discovery_enabled'] = true;
$repository->save($userId, 'automation', $automation);
(new QueueRepository())->enqueue($userId, 'discovery');
echo "BrighterMonday and Fuzu: added; Gmail and official subscriptions are required.\n";
echo "Scheduled discovery enabled. The existing application submission mode is preserved.\n";
