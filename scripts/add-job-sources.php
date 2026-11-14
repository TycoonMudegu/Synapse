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
foreach ($ids as $key => $sourceId) {
    if (in_array('--public', $argv, true) && $key !== 'myjobmag') {
        (new SourcePresetService())->selectMode($userId, $sourceId, 'public');
    }
    $source = $sources->get($userId, $sourceId);
    if ($source['adapter'] === 'email_alert') {
        echo $source['name'] .
            ": optional email mode retained; Gmail and subscriptions are required. Use --public to switch.\n";
        continue;
    }
    try {
        (new DiscoveryService())->adapter($source['adapter'])->validate($source);
        $sources->health($userId, $sourceId, true);
        echo $source['name'] . ": public connection and vacancy fields verified.\n";
    } catch (Throwable $exception) {
        $message =
            $exception instanceof \App\Core\HttpException
                ? $exception->getMessage()
                : 'Public connection validation failed.';
        $sources->health($userId, $sourceId, false, $message);
        echo $source['name'] . ': ' . $message . PHP_EOL;
    }
}
$repository = new SettingsRepository();
$automation = $repository->get($userId, 'automation');
$automation['discovery_enabled'] = true;
$repository->save($userId, 'automation', $automation);
(new QueueRepository())->enqueue($userId, 'discovery');
echo "Scheduled discovery enabled. The existing application submission mode is preserved.\n";
