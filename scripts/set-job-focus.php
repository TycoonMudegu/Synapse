<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\SettingsService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$focus = $argv[2] ?? 'technology';
if (!(new UserRepository())->byId($userId) || !in_array($focus, ['technology', 'any'], true)) {
    fwrite(STDERR, "Usage: php scripts/set-job-focus.php <existing-user-id> [technology|any]\n");
    exit(1);
}
$settings = (new SettingsRepository())->get($userId, 'discovery');
(new SettingsService())->save($userId, 'discovery', [...$settings, 'role_focus' => $focus]);
(new QueueRepository())->enqueue($userId, 'discovery');
echo 'Role focus saved: ' . $focus . ". Discovery queued with the existing limits and schedule.\n";
