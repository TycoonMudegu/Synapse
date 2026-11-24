<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Repositories\IntegrationRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$queue = new QueueRepository();
if (!$queue->lock('jobflow:scheduler')) {
    exit(0);
}
try {
    $queue->recoverStale();
    foreach ($queue->users() as $user) {
        $userId = (int) $user['id'];
        if (\App\Services\Documents\DocumentStorage::enabled()) {
            foreach (
                (new \App\Repositories\DocumentRepository())->archiveCandidates($userId)
                as $document
            ) {
                $queue->enqueue($userId, 'archive_document', [
                    'document_id' => (int) $document['id'],
                ]);
            }
        }
        $settings = new SettingsRepository();
        $automation = $settings->get($userId, 'automation');
        $paid = (new \App\Services\Entitlements())->paid($userId);
        if (!$paid) {
            $automation['discovery_enabled'] = false;
            $automation['mode'] = 'manual';
        }
        if ($automation['paused']) {
            continue;
        }
        (new \App\Services\AutomationService())->queueSavedJobs($userId);
        $discovery = $settings->get($userId, 'discovery');
        $latest = $queue->latestRun($userId, 'discovery');
        if (
            $automation['discovery_enabled'] &&
            (!$latest ||
                strtotime($latest['started_at']) <=
                    time() - (int) $discovery['interval_minutes'] * 60)
        ) {
            $queue->enqueue($userId, 'discovery');
        }
        $deadlines = $queue->latestRun($userId, 'verify_deadlines');
        if (!$deadlines || substr($deadlines['started_at'], 0, 10) < date('Y-m-d')) {
            $queue->enqueue($userId, 'verify_deadlines');
        }
        $gmail = $settings->get($userId, 'gmail');
        $connection = (new IntegrationRepository())->connection($userId);
        if (
            $paid &&
            $connection &&
            ($gmail['monitor_enabled'] || $gmail['response_tracking']) &&
            (!$connection['last_sync_at'] ||
                strtotime($connection['last_sync_at']) <=
                    time() - (int) $gmail['sync_minutes'] * 60)
        ) {
            $queue->enqueue($userId, 'gmail_sync');
        }
        (new \App\Services\AutomationService())->queueApprovedApplications($userId);
        (new \App\Services\Mail\ManualJobAlertService())->queueForUser($userId);
    }
    $queue->pruneTransientRecords();
} finally {
    $queue->unlock('jobflow:scheduler');
}
