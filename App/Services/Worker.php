<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Services\AI\MatchingService;
use App\Services\Discovery\DiscoveryService;
use App\Services\Documents\PreparationService;
use App\Services\Mail\GmailService;

final class Worker
{
    public function __construct(private readonly array $taskTypes = []) {}

    public function tick(): bool
    {
        $queue = new QueueRepository();
        $task = $queue->claim($this->taskTypes);
        file_put_contents(BASE_PATH . '/storage/worker-heartbeat', (string) time(), LOCK_EX);
        if (!$task) {
            return false;
        }
        $userId = (int) $task['user_id'];
        $lock = 'jobflow:' . $userId . ':' . $task['type'];
        if (!$queue->lock($lock)) {
            $queue->defer($task);
            return true;
        }
        try {
            $settings = (new SettingsRepository())->get($userId, 'automation');
            if ($settings['paused']) {
                $queue->defer($task);
                return true;
            }
            $payload = json_decode($task['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            match ($task['type']) {
                'discovery' => (new DiscoveryService())->run($userId),
                'analyse_job' => $this->analyse($userId, (int) $payload['job_id']),
                'prepare_application' => (new PreparationService())->prepare(
                    $userId,
                    (int) $payload['application_id'],
                ),
                'submit_application' => (new ApplicationService())->send(
                    $userId,
                    (int) $payload['application_id'],
                ),
                'gmail_sync' => $this->sync($userId),
                'verify_deadlines' => $this->deadlines($userId),
                default => throw new HttpException('Unknown background task type.'),
            };
            $queue->complete($task);
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpException && $exception->status === 429) {
                $queue->defer($task);
                return true;
            }
            $message =
                $exception instanceof HttpException
                    ? $exception->getMessage()
                    : 'Task failed. Check the server configuration and system log.';
            $queue->fail($task, $message);
            (new ActivityRepository())->notify(
                $userId,
                'error',
                'Background task needs attention',
                $message,
                '/settings?tab=system',
            );
            if (!($exception instanceof HttpException)) {
                error_log(
                    'Worker task ' .
                        $task['id'] .
                        ': ' .
                        get_class($exception) .
                        ' at ' .
                        $exception->getFile() .
                        ':' .
                        $exception->getLine(),
                );
            }
        } finally {
            $queue->unlock($lock);
        }
        return true;
    }

    private function analyse(int $userId, int $jobId): void
    {
        $result = (new MatchingService())->analyse($userId, $jobId);
        $settings = new SettingsRepository();
        $automation = $settings->get($userId, 'automation');
        $discovery = $settings->get($userId, 'discovery');
        if (
            $automation['preparation_enabled'] &&
            $result['eligibility'] === 'eligible' &&
            $result['score'] >= $discovery['minimum_match']
        ) {
            $job = (new JobRepository())->get($userId, $jobId);
            $id = (new ApplicationRepository())->create($userId, $job);
            (new QueueRepository())->enqueue($userId, 'prepare_application', [
                'application_id' => $id,
            ]);
        }
    }

    private function sync(int $userId): void
    {
        $queue = new QueueRepository();
        $run = $queue->startRun($userId, 'gmail_sync');
        try {
            $count = (new GmailService())->sync($userId);
            $queue->endRun($run, 'completed', $count);
        } catch (\Throwable $exception) {
            (new \App\Repositories\SourceRepository())->emailHealth(
                $userId,
                'error',
                $exception instanceof HttpException
                    ? $exception->getMessage()
                    : 'Gmail synchronisation failed.',
            );
            $queue->endRun(
                $run,
                'failed',
                0,
                error: $exception instanceof HttpException
                    ? $exception->getMessage()
                    : 'Gmail synchronisation failed.',
            );
            throw $exception;
        }
    }

    private function deadlines(int $userId): void
    {
        $count = (new JobRepository())->expire($userId);
        $queue = new QueueRepository();
        $run = $queue->startRun($userId, 'verify_deadlines');
        $queue->endRun($run, 'completed', $count);
        $stats = (new JobRepository())->dashboard($userId);
        foreach ($stats['deadlines'] as $job) {
            if ($job['deadline'] <= date('Y-m-d', strtotime('+3 days'))) {
                (new ActivityRepository())->notify(
                    $userId,
                    'deadline',
                    'Application deadline approaching',
                    $job['title'] . ' closes ' . short_date($job['deadline']),
                    '/jobs/' . $job['id'],
                );
            }
        }
    }
}
