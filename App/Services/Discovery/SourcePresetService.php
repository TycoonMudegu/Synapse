<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Repositories\ActivityRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;

final class SourcePresetService
{
    public function install(int $userId, array $keys = []): array
    {
        $keys = $keys ?: array_keys(SourceCatalog::SOURCES);
        $sources = new SourceRepository();
        $ids = [];
        foreach ($keys as $key) {
            $definition = SourceCatalog::get($key);
            $ids[$key] = $sources->ensurePreset($userId, $key, $definition);
        }
        if (array_intersect($keys, ['brightermonday', 'fuzu'])) {
            $repository = new SettingsRepository();
            $settings = $repository->get($userId, 'gmail');
            $settings['monitor_enabled'] = true;
            $repository->save($userId, 'gmail', $settings);
            $this->refreshEmailHealth($userId);
        }
        (new ActivityRepository())->log(
            $userId,
            'sources.added',
            'Added the requested job-source presets. Connection checks are required before collection.',
        );
        return $ids;
    }

    public function refreshEmailHealth(int $userId): void
    {
        $connection = (new IntegrationRepository())->connection($userId);
        if (!$connection) {
            (new SourceRepository())->emailHealth(
                $userId,
                'needs_connection',
                'Connect Gmail to receive this site’s alerts.',
            );
        } elseif (!str_contains($connection['scopes'], 'gmail.readonly')) {
            (new SourceRepository())->emailHealth(
                $userId,
                'needs_permission',
                'Enable monitoring and reconnect Gmail to grant read-only access.',
            );
        }
    }
}
