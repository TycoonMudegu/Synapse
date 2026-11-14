<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Repositories\ActivityRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;

final class SourcePresetService
{
    public function install(int $userId, array $keys = [], string $mode = 'public'): array
    {
        $keys = $keys ?: array_keys(SourceCatalog::SOURCES);
        $sources = new SourceRepository();
        $ids = [];
        foreach ($keys as $key) {
            $definition = SourceCatalog::get($key, $key === 'myjobmag' ? 'public' : $mode);
            $ids[$key] = $sources->ensurePreset($userId, $key, $definition);
        }
        if ($sources->emailSources($userId) !== []) {
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

    public function selectMode(int $userId, int $id, string $mode): void
    {
        $repository = new SourceRepository();
        $source = $repository->get($userId, $id);
        if (!in_array($source['preset_key'], ['brightermonday', 'fuzu'], true)) {
            throw new \App\Core\HttpException(
                'Collection modes are available for BrighterMonday and Fuzu.',
            );
        }
        $definition = SourceCatalog::get($source['preset_key'], $mode);
        if ($source['adapter'] !== $definition['adapter']) {
            $repository->collectionMode($userId, $id, $definition);
        }
        if ($mode === 'email') {
            $settings = new SettingsRepository();
            $settings->save($userId, 'gmail', [
                ...$settings->get($userId, 'gmail'),
                'monitor_enabled' => true,
            ]);
            $this->refreshEmailHealth($userId);
        }
        (new ActivityRepository())->log(
            $userId,
            'source.mode',
            'Changed ' . $source['name'] . ' collection to ' . $mode . '.',
        );
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
