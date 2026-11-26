<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Repositories\CatalogueRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Services\JobService;

/** Central collection mode: user discovery searches stored vacancies without visiting job sites. */
final class CatalogueDiscovery
{
    public static function enabled(): bool
    {
        return \App\Core\Env::bool('COLLECTOR_ENABLED', true);
    }

    public function run(int $userId): int
    {
        $queue = new QueueRepository();
        $lock = 'synapse:discovery:' . $userId;
        if (!$queue->lock($lock)) {
            throw new HttpException('Your job search is already running.', 409);
        }
        try {
            return $this->search($userId);
        } finally {
            $queue->unlock($lock);
        }
    }

    private function search(int $userId): int
    {
        $settings = new SettingsRepository();
        if ($settings->get($userId, 'automation')['paused']) {
            throw new HttpException('Automation is paused. Resume it before searching.');
        }
        $preferences = $settings->get($userId, 'discovery');
        $jobs = new JobRepository();
        $limit = min(
            (int) $preferences['jobs_per_run'],
            max(0, (int) $preferences['daily_limit'] - $jobs->discoveredToday($userId)),
        );
        if ($limit < 1) {
            throw new HttpException('The daily discovery limit has been reached.');
        }
        $queue = new QueueRepository();
        $run = $queue->startRun($userId, 'discovery');
        $found = 0;
        try {
            $catalogue = new CatalogueRepository();
            $before = null;
            while ($found < $limit) {
                $entries = $catalogue->discoveryPage($userId, $before);
                foreach ($entries as $entry) {
                    if (
                        !(new DiscoveryService())->matches($entry['job'], $preferences) ||
                        $jobs->duplicateId(
                            $userId,
                            (new JobService())->normalise($entry['job']),
                        ) !== null
                    ) {
                        continue;
                    }
                    $result = $catalogue->saveForUser((int) $entry['id'], $userId);
                    $found += (int) !$result['duplicate'];
                    if ($found >= $limit) {
                        break;
                    }
                }
                if (count($entries) < 100) {
                    break;
                }
                $before = (int) $entries[array_key_last($entries)]['id'];
            }
            $queue->endRun($run, 'completed', $found);
            return $found;
        } catch (\Throwable $error) {
            $queue->endRun(
                $run,
                'failed',
                $found,
                error: 'The shared catalogue could not be searched.',
            );
            throw $error;
        }
    }
}
