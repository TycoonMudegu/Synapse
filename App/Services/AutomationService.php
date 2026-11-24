<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Repositories\AccountRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;

final class AutomationService
{
    public function status(int $userId): array
    {
        $settings = new SettingsRepository();
        $automation = $settings->get($userId, 'automation');
        $profile = (new ProfileRepository())->get($userId);
        $paid = (new Entitlements())->paid($userId);
        $ai = $this->aiReady($userId, $settings);
        $blockers = [];
        if (!$paid) {
            $blockers[] = [
                'message' => 'Activate Plus to use application automation.',
                'href' => '/plans',
            ];
        }
        if ($automation['paused']) {
            $blockers[] = [
                'message' => 'Automation is paused.',
                'href' => '/settings?tab=automation',
            ];
        }
        if (!$automation['analysis_enabled'] || !$automation['preparation_enabled']) {
            $blockers[] = [
                'message' => 'Matching and application preparation are switched off.',
                'href' => '/settings?tab=automation',
            ];
        }
        if (empty($profile['verified_at']) || trim($profile['summary'] ?? '') === '') {
            $blockers[] = [
                'message' =>
                    'Import your uploaded CV into your career profile, then check and save it.',
                'href' => '/profile',
            ];
        }
        if (!$ai) {
            $blockers[] = [
                'message' => 'An AI connection is needed for matching and tailored documents.',
                'href' => '/settings?tab=ai',
            ];
        }
        if (!(new IntegrationRepository())->connection($userId)) {
            $blockers[] = [
                'message' => 'Connect Gmail before email applications can be sent.',
                'href' => '/settings?tab=gmail',
            ];
        }
        if (
            $settings->get($userId, 'documents')['layout_mode'] === 'preserve' &&
            !(new \App\Services\Documents\RendererClient())->configured()
        ) {
            $blockers[] = [
                'message' =>
                    'Keeping your document design is waiting for the site owner to finish document rendering setup.',
                'href' => '/settings?tab=documents',
            ];
        }
        $heartbeat = BASE_PATH . '/storage/application-worker-heartbeat';
        if (!is_file($heartbeat) || (int) file_get_contents($heartbeat) < time() - 300) {
            $blockers[] = [
                'message' =>
                    'Background application processing is not running. The site owner needs to start it.',
                'href' => '/settings?tab=automation',
            ];
        }
        return [
            'mode' => $automation['mode'],
            'blockers' => $blockers,
            'can_analyse' =>
                $paid &&
                !$automation['paused'] &&
                $automation['analysis_enabled'] &&
                !empty($profile['verified_at']) &&
                $ai,
            'email_jobs' => (new JobRepository())->emailOpportunities($userId),
            'smtp_ready' => (new \App\Services\Mail\SmtpMailer())->configured(),
        ];
    }

    public function queueSavedJobs(int $userId): int
    {
        if (!$this->status($userId)['can_analyse']) {
            return 0;
        }
        $queue = new QueueRepository();
        $jobs = (new JobRepository())->awaitingAnalysis($userId);
        foreach ($jobs as $job) {
            $queue->enqueue($userId, 'analyse_job', ['job_id' => (int) $job['id']]);
        }
        return count($jobs);
    }

    public function queueApprovedApplications(int $userId): int
    {
        $settings = (new SettingsRepository())->get($userId, 'automation');
        if (
            $settings['paused'] ||
            !in_array($settings['mode'], ['approval', 'automatic'], true) ||
            !in_array('email', $settings['permitted_methods'], true) ||
            !(new Entitlements())->paid($userId)
        ) {
            return 0;
        }
        $queued = 0;
        foreach (
            (new ApplicationRepository())->all(
                $userId,
                ['status' => 'approved', 'sort' => 'match'],
                200,
            )
            as $application
        ) {
            if (
                $application['method'] !== 'email' ||
                $application['send_key'] ||
                $application['submitted_at'] ||
                !filter_var($application['application_email'], FILTER_VALIDATE_EMAIL) ||
                !in_array($application['job_status'], ['saved', 'shortlisted'], true) ||
                ($application['deadline'] && $application['deadline'] < date('Y-m-d')) ||
                ($settings['mode'] === 'automatic' &&
                    ($application['eligibility'] !== 'eligible' ||
                        (int) $application['score'] < (int) $settings['minimum_match']))
            ) {
                continue;
            }
            (new QueueRepository())->enqueue($userId, 'submit_application', [
                'application_id' => (int) $application['id'],
            ]);
            $queued++;
        }
        return $queued;
    }

    private function aiReady(int $userId, SettingsRepository $settings): bool
    {
        $assignments = $settings->get($userId, 'ai');
        $provider = $settings->provider($userId, (int) $assignments['screening_provider']);
        if ($provider && $provider['encrypted_key'] !== '' && $provider['model'] !== '') {
            $options = json_decode($provider['options_json'], true);
            return ($options['input_per_million'] ?? 0) > 0 &&
                ($options['output_per_million'] ?? 0) > 0;
        }
        if (Env::get('GEMINI_API_KEY') !== '') {
            return Env::get('GEMINI_MODEL') !== '' &&
                (float) Env::get('GEMINI_INPUT_PER_MILLION') > 0 &&
                (float) Env::get('GEMINI_OUTPUT_PER_MILLION') > 0;
        }
        $managed = (new AccountRepository())->site('ai_provider');
        $options = json_decode($managed['options_json'] ?? '{}', true);
        return !empty($managed['encrypted_key']) &&
            !empty($managed['model']) &&
            ($options['input_per_million'] ?? 0) > 0 &&
            ($options['output_per_million'] ?? 0) > 0;
    }
}
