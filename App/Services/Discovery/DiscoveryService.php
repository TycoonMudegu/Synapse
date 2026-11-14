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
    /** @param array<string, SourceAdapter> $adapters */
    public function __construct(private readonly array $adapters = []) {}

    public function adapter(string $type): SourceAdapter
    {
        if (isset($this->adapters[$type])) {
            return $this->adapters[$type];
        }
        return match ($type) {
            'rss' => new FeedAdapter(),
            'myjobmag' => new MyJobMagAdapter(),
            'brightermonday_public' => new PublicJobsAdapter('brightermonday'),
            'fuzu_public' => new PublicJobsAdapter('fuzu'),
            'email_alert' => new EmailAlertAdapter(),
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
                'Check and enable a public job source in Settings → Job discovery, or connect Gmail for optional email alerts.',
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
            try {
                if (
                    $source['adapter'] !== 'email_alert' &&
                    $source['last_checked_at'] &&
                    strtotime($source['last_checked_at']) > time() - 60
                ) {
                    continue;
                }
                $checked++;
                // Scan the bounded structured feed before spending the processing allowance.
                // Plain-text sources still respect the AI extraction limit and budget.
                $items = $this->adapter($source['adapter'])->collect(
                    $source,
                    match ($source['adapter']) {
                        'myjobmag' => 200,
                        'brightermonday_public', 'fuzu_public' => 20,
                        default => $limit - $processed,
                    },
                );
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
                if ($processed >= $limit) {
                    break;
                }
                try {
                    if (isset($item['structured'])) {
                        if (!$this->matches($item['structured'], $settings)) {
                            continue;
                        }
                        if (
                            (new JobRepository())->duplicateId(
                                $userId,
                                (new JobService())->normalise($item['structured']),
                            ) !== null
                        ) {
                            continue;
                        }
                    }
                    $processed++;
                    $spent =
                        array_sum(array_column($settingsRepo->usage($userId), 'estimated_cost')) -
                        $budgetStart;
                    $job =
                        $item['structured'] ??
                        (new AiService())->run(
                            $userId,
                            'extract_job',
                            ['advertisement' => $item['text'], 'target_url' => $item['url'] ?? ''],
                            runBudget: max(0, (float) $settings['run_budget_usd'] - $spent),
                        );
                    if ($job['title'] === '' || $job['employer'] === '') {
                        if (isset($item['alert_item_id'])) {
                            (new \App\Repositories\AlertRepository())->finish(
                                $userId,
                                $item['alert_item_id'],
                                'needs_review',
                                error: 'The alert does not include enough facts for this vacancy. Review the original email.',
                            );
                        }
                        continue;
                    }
                    if (!$this->matches($job, $settings)) {
                        if (isset($item['alert_item_id'])) {
                            (new \App\Repositories\AlertRepository())->finish(
                                $userId,
                                $item['alert_item_id'],
                                'filtered',
                            );
                        }
                        continue;
                    }
                    $job['original_url'] = $job['original_url'] ?? ($item['url'] ?? '');
                    $job['source_id'] = (int) $source['id'];
                    $job['verified'] = false;
                    $job['poster_document_id'] = null;
                    $result = (new JobService())->create($userId, $job);
                    $found += (int) !$result['duplicate'];
                    if (isset($item['alert_item_id'])) {
                        (new \App\Repositories\AlertRepository())->finish(
                            $userId,
                            $item['alert_item_id'],
                            'processed',
                            $result['id'],
                        );
                    }
                } catch (\Throwable $exception) {
                    if (isset($item['alert_item_id'])) {
                        (new \App\Repositories\AlertRepository())->finish(
                            $userId,
                            $item['alert_item_id'],
                            'error',
                            error: $exception instanceof HttpException
                                ? $exception->getMessage()
                                : 'The alert could not be imported.',
                        );
                    }
                    $errors[] =
                        $exception instanceof HttpException
                            ? $exception->getMessage()
                            : 'A vacancy could not be processed.';
                }
            }
        }
        if ($checked === 0) {
            $queue->endRun(
                $run,
                'deferred',
                0,
                error: 'Sources were checked recently; retry after one minute.',
            );
            throw new HttpException(
                'Sources were checked recently. Collection will retry after one minute.',
                429,
            );
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
        if (
            ($settings['role_focus'] ?? 'any') === 'technology' &&
            !TechnologyRoles::matches($job)
        ) {
            return false;
        }
        $haystack = mb_strtolower(
            ($job['title'] ?? '') .
                ' ' .
                ($job['description'] ?? '') .
                ' ' .
                ($job['industry'] ?? ''),
        );
        $keywords = array_filter(array_map('trim', explode(',', $settings['keywords'])));
        if ($keywords && !KeywordMatcher::any($haystack, $keywords)) {
            return false;
        }
        foreach (
            array_filter(array_map('trim', explode(',', $settings['excluded_keywords'])))
            as $word
        ) {
            if (KeywordMatcher::contains($haystack, $word)) {
                return false;
            }
        }
        $categories = array_filter(array_map('trim', explode(',', $settings['categories'])));
        if ($categories && !KeywordMatcher::any($haystack, $categories)) {
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
                fn($place) => LocationMatcher::matches($job['location'] ?? '', $place),
            )
        ) {
            return false;
        }
        return true;
    }
}
