<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Services\AI\AiService;
use App\Services\JobService;

final class DiscoveryService
{
    public function adapter(string $type): SourceAdapter
    {
        return match ($type) {
            'rss' => new FeedAdapter(),
            'json' => new JsonFeedAdapter(),
            default => throw new HttpException('This source has no supported adapter.'),
        };
    }

    public function run(int $userId): int
    {
        $settingsRepo = new SettingsRepository();
        $settings = $settingsRepo->get($userId, 'discovery');
        $automation = $settingsRepo->get($userId, 'automation');
        if ($automation['paused']) {
            throw new HttpException(
                'Automation is paused. Resume it in Settings before searching.',
            );
        }
        $limit = min(
            (int) $settings['jobs_per_run'],
            max(
                0,
                (int) $settings['daily_limit'] - (new JobRepository())->discoveredToday($userId),
            ),
        );
        if ($limit <= 0) {
            throw new HttpException('The daily discovery limit has been reached.');
        }
        $sources = new SourceRepository();
        $enabled = $sources->enabled($userId, (int) $settings['sources_per_run']);
        if ($enabled === []) {
            throw new HttpException(
                'Add and successfully test a permitted RSS or JSON source in Settings → Job discovery.',
            );
        }
        $queue = new QueueRepository();
        $run = $queue->startRun($userId, 'discovery');
        $processed = 0;
        $found = 0;
        $checked = 0;
        $errors = [];
        $budgetStart = array_sum(array_column($settingsRepo->usage($userId), 'estimated_cost'));
        foreach ($enabled as $source) {
            if ($processed >= $limit) {
                break;
            }
            $checked++;
            try {
                if (
                    $source['last_checked_at'] &&
                    strtotime($source['last_checked_at']) > time() - 60
                ) {
                    continue;
                }
                $items = $this->adapter($source['adapter'])->collect($source, $limit - $processed);
                $sources->health($userId, (int) $source['id'], true);
            } catch (\Throwable $exception) {
                $message =
                    $exception instanceof HttpException
                        ? $exception->getMessage()
                        : 'Collection failed. Check the source configuration.';
                $sources->health($userId, (int) $source['id'], false, $message);
                $errors[] = $source['name'] . ': ' . $message;
                continue;
            }
            foreach ($items as $item) {
                $processed++;
                try {
                    $spent =
                        array_sum(array_column($settingsRepo->usage($userId), 'estimated_cost')) -
                        $budgetStart;
                    $job =
                        $item['structured'] ??
                        (new AiService())->run(
                            $userId,
                            'extract_job',
                            ['advertisement' => $item['text']],
                            runBudget: max(0, (float) $settings['run_budget_usd'] - $spent),
                        );
                    if (
                        $job['title'] === '' ||
                        $job['employer'] === '' ||
                        !$this->matches($job, $settings)
                    ) {
                        continue;
                    }
                    $job['original_url'] = $job['original_url'] ?? ($item['url'] ?? '');
                    $job['source_id'] = (int) $source['id'];
                    $job['verified'] = false;
                    $job['poster_document_id'] = null;
                    $result = (new JobService())->create($userId, $job);
                    $found += (int) !$result['duplicate'];
                } catch (\Throwable $exception) {
                    $errors[] =
                        $exception instanceof HttpException
                            ? $exception->getMessage()
                            : 'A vacancy could not be processed.';
                }
            }
        }
        $queue->endRun(
            $run,
            $errors ? ($found ? 'partial' : 'failed') : 'completed',
            $found,
            $checked,
            $errors ? implode(' ', array_unique($errors)) : null,
        );
        (new ActivityRepository())->notify(
            $userId,
            'discovery',
            'Discovery run finished',
            $found . ' new opportunities saved. ' . count($errors) . ' processing errors.',
            '/discover',
        );
        return $found;
    }

    public function matches(array $job, array $settings): bool
    {
        $haystack = mb_strtolower(($job['title'] ?? '') . ' ' . ($job['description'] ?? ''));
        $keywords = array_filter(array_map('trim', explode(',', $settings['keywords'])));
        if (
            $keywords &&
            !array_filter($keywords, fn($word) => str_contains($haystack, mb_strtolower($word)))
        ) {
            return false;
        }
        foreach (
            array_filter(array_map('trim', explode(',', $settings['excluded_keywords'])))
            as $word
        ) {
            if (str_contains($haystack, mb_strtolower($word))) {
                return false;
            }
        }
        $categories = array_filter(array_map('trim', explode(',', $settings['categories'])));
        if (
            $categories &&
            !array_filter(
                $categories,
                fn($category) => str_contains($haystack, mb_strtolower($category)),
            )
        ) {
            return false;
        }
        if (
            $settings['ignore_expired'] &&
            !empty($job['deadline']) &&
            $job['deadline'] < date('Y-m-d')
        ) {
            return false;
        }
        if (
            (float) $settings['minimum_salary'] > 0 &&
            isset($job['salary_max']) &&
            (float) $job['salary_max'] < (float) $settings['minimum_salary']
        ) {
            return false;
        }
        if (
            $settings['remote'] !== 'any' &&
            ($job['workplace'] ?? 'not_specified') !== $settings['remote']
        ) {
            return false;
        }
        $locations = array_filter(array_map('trim', explode(',', $settings['locations'])));
        if (
            $locations &&
            !array_filter(
                $locations,
                fn($place) => str_contains(
                    mb_strtolower($job['location'] ?? ''),
                    mb_strtolower($place),
                ),
            )
        ) {
            return false;
        }
        return true;
    }
}
