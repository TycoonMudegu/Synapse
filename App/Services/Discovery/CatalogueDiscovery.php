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
    public function run(int $userId): int
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
            for ($page = 1; $page <= 20 && $found < $limit; $page++) {
                $entries = $catalogue->list('', false, $page);
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
                if (count($entries) < 30) {
                    break;
                }
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
