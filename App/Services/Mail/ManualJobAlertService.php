<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\JobRepository;
use App\Repositories\ManualJobAlertRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\Entitlements;

final class ManualJobAlertService
{
    public function __construct(private readonly SmtpMailer $mailer = new SmtpMailer()) {}

    public function queueForUser(int $userId): int
    {
        $settings = (new SettingsRepository())->get($userId, 'automation');
        if (!$this->enabled($userId, $settings)) {
            return 0;
        }
        $alerts = new ManualJobAlertRepository();
        $queued = 0;
        foreach ($alerts->candidates($userId, (int) $settings['minimum_match']) as $job) {
            $id = $alerts->ensure($userId, (int) $job['id']);
            // Keep an outbox record while credentials are absent; do not block application tasks.
            if ($this->mailer->configured()) {
                (new QueueRepository())->enqueue($userId, 'manual_job_alert', ['alert_id' => $id]);
                $queued++;
            }
        }
        return $queued;
    }

    public function deliver(int $userId, int $id): void
    {
        $alerts = new ManualJobAlertRepository();
        $delivery = Database::transaction(function () use ($alerts, $userId, $id): ?array {
            $alerts->lockUser($userId);
            $alert = $alerts->get($userId, $id, true);
            $settings = (new SettingsRepository())->get($userId, 'automation');
            if (
                $alert['status'] !== 'pending' ||
                !$this->enabled($userId, $settings) ||
                !$this->mailer->configured()
            ) {
                return null;
            }
            $job = (new JobRepository())->get($userId, (int) $alert['job_id']);
            $application = $alerts->application($userId, (int) $job['id']);
            if (!$this->eligible($job, $application, (int) $settings['minimum_match'])) {
                return null;
            }
            $user = (new UserRepository())->byId($userId);
            if (!$user || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(
                    'A valid account email is required for manual application alerts.',
                );
            }
            $messageId = '<manual-job-' . $id . '-' . bin2hex(random_bytes(12)) . '@jobflow.local>';
            $alerts->reserve($userId, $id, $messageId);
            return compact('job', 'application', 'user', 'messageId');
        });
        if ($delivery === null) {
            return;
        }
        [
            'job' => $job,
            'application' => $application,
            'user' => $user,
            'messageId' => $messageId,
        ] = $delivery;
        try {
            $path = $application ? '/applications/' . $application['id'] : '/jobs/' . $job['id'];
            $workspace = rtrim(Env::get('APP_URL'), '/') . $path;
            $employerUrl = $this->employerUrl($job['original_url'] ?? '');
            $documents =
                $application && $application['cv_document_id'] && $application['letter_document_id']
                    ? 'A tailored CV and cover letter are available in your application workspace. Review them before uploading.'
                    : 'Open the job in your workspace to prepare or upload your CV and cover letter.';
            $lines = [
                'Hi ' . $user['name'] . ',',
                '',
                'You are a strong match for a job that needs a manual application.',
                '',
                'Role: ' . $job['title'],
                'Employer: ' . $job['employer'],
                'Location: ' . ($job['location'] ?: 'Not supplied'),
                'Match: ' . (int) $job['score'] . '% (eligible)',
                'Deadline: ' . short_date($job['deadline']),
                '',
                'This job does not offer a supported email application. Nothing has been submitted.',
                $employerUrl
                    ? 'Apply on the employer website: ' . $employerUrl
                    : 'Check the application instructions in your workspace.',
                '',
                $documents,
                'Your workspace: ' . $workspace,
                '',
                'Automation will continue looking for eligible email applications. This alert does not use your daily application limit.',
                'After applying yourself, record the submission confirmation in your workspace.',
            ];
            $text = implode("\n", $lines);
            // Never render untrusted job HTML or load remote images in a notification.
            $html = '<!doctype html><html><body>';
            foreach ($lines as $line) {
                $html .= '<p>' . e($line) . '</p>';
            }
            $html .= '<p><a href="' . e($workspace) . '">Open your application workspace</a></p>';
            if ($employerUrl) {
                $html .= '<p><a href="' . e($employerUrl) . '">Open employer website</a></p>';
            }
            $html .= '</body></html>';
            $this->mailer->send(
                $user['email'],
                $user['name'],
                'Apply manually: ' . $job['title'] . ' at ' . $job['employer'],
                $text,
                $html,
                $messageId,
            );
            $alerts->sent($userId, $id);
        } catch (\Throwable $exception) {
            $alerts->failed($userId, $id);
            throw $exception;
        }
        (new ActivityRepository())->log(
            $userId,
            'manual_job_alert.sent',
            'Emailed a manual application opportunity: ' .
                $job['title'] .
                '. No application was submitted.',
        );
        (new ActivityRepository())->notify(
            $userId,
            'manual_application',
            'Strong match emailed for manual application',
            $job['title'] .
                ' — apply on the employer website. Automation is continuing with email opportunities.',
            $path,
        );
    }

    private function enabled(int $userId, array $settings): bool
    {
        return $settings['mode'] === 'automatic' &&
            !$settings['paused'] &&
            (new Entitlements())->paid($userId);
    }

    private function eligible(array $job, ?array $application, int $minimumMatch): bool
    {
        return $job['application_method'] !== 'email' &&
            in_array($job['status'], ['saved', 'shortlisted'], true) &&
            (!$job['deadline'] || $job['deadline'] >= date('Y-m-d')) &&
            $job['eligibility'] === 'eligible' &&
            (int) $job['score'] >= $minimumMatch &&
            (!$application ||
                (in_array(
                    $application['status'],
                    ['draft', 'ready_for_review', 'approved', 'awaiting_external_action'],
                    true,
                ) &&
                    !$application['submitted_at'] &&
                    !$application['send_key']));
    }

    private function employerUrl(string $url): string
    {
        // This link is displayed, never fetched. Reject active schemes and header/control injection.
        $parts = parse_url($url);
        return $parts &&
            in_array($parts['scheme'] ?? '', ['https', 'http'], true) &&
            !empty($parts['host']) &&
            !isset($parts['user']) &&
            !isset($parts['pass']) &&
            !preg_match('/[\x00-\x20\x7f]/', $url)
            ? $url
            : '';
    }
}
