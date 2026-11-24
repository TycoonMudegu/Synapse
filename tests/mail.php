<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\ApplicationRepository;
use App\Repositories\JobRepository;
use App\Repositories\ManualJobAlertRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AutomationService;
use App\Services\JobService;
use App\Services\Mail\ManualJobAlertService;
use App\Services\Mail\SmtpMailer;
use PHPMailer\PHPMailer\PHPMailer;

require BASE_PATH . '/scripts/migrate.php';
$passed = 0;
$users = [];

function mail_check(string $name, callable $operation): void
{
    global $passed;
    $operation();
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}

function mail_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}

function mail_denied(callable $operation, int $status = 404): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        mail_same($status, $exception->status);
        return;
    }
    throw new RuntimeException('Expected rejection.');
}

function mail_job(
    int $user,
    int $score = 95,
    string $eligibility = 'eligible',
    string $method = 'external',
    array $overrides = [],
): int {
    $data = (new JobService())->normalise(
        array_replace(
            [
                'title' => 'Fixture role ' . bin2hex(random_bytes(6)),
                'employer' => 'Fixture <em>Employer</em>',
                'description' => 'A synthetic vacancy; never submit.',
                'original_url' => 'https://example.com/jobs/' . bin2hex(random_bytes(6)),
                'application_method' => $method,
                'application_email' => $method === 'email' ? 'recruiter@example.test' : '',
                'deadline' => '2099-12-31',
                'verified' => true,
            ],
            $overrides,
        ),
    );
    $id = (new JobRepository())->insert($user, $data)['id'];
    Database::connection()
        ->prepare(
            'INSERT INTO match_results (user_id, job_id, score, eligibility, result_json, profile_updated_at) VALUES (?, ?, ?, ?, ?, NOW())',
        )
        ->execute([$user, $id, $score, $eligibility, '{}']);
    return $id;
}

final class RecordingMailer extends PHPMailer
{
    public int $calls = 0;
    public bool $fail = false;
    public string $mime = '';

    public function send(): bool
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('Sensitive SMTP provider response: fixture-app-password');
        }
        if (!$this->preSend()) {
            throw new RuntimeException('Fixture MIME preparation failed.');
        }
        $this->mime = $this->getSentMIMEMessage();
        return true; // No SMTP connection or other external side effect.
    }
}

try {
    $repository = new UserRepository();
    $user = $repository->create(
        'Mail Fixture',
        'mail-' . bin2hex(random_bytes(6)) . '@example.test',
        'fixture-password-12345',
    );
    $users[] = $user;
    $other = $repository->create(
        'Other Mail Fixture',
        'mail-other-' . bin2hex(random_bytes(6)) . '@example.test',
        'fixture-password-12345',
    );
    $users[] = $other;
    $db = Database::connection();
    $db->prepare("INSERT INTO memberships (user_id, plan) VALUES (?, 'lifetime')")->execute([
        $user,
    ]);
    $settings = new SettingsRepository();
    $settings->save($user, 'automation', [
        'mode' => 'automatic',
        'minimum_match' => 80,
        'daily_limit' => 1,
    ]);
    $settings->save($other, 'automation', ['mode' => 'automatic']);
    $alerts = new ManualJobAlertRepository();
    $applications = new ApplicationRepository();
    $queue = new QueueRepository();
    $recording = new RecordingMailer(true);
    $smtp = new SmtpMailer($recording);
    $service = new ManualJobAlertService($smtp);
    $job = mail_job($user, 98);
    $application = $applications->create($user, (new JobRepository())->get($user, $job));
    $applications->update($user, $application, ['status' => 'awaiting_external_action']);
    $originalApplication = $applications->get($user, $application);
    mail_job($user, 79);
    mail_job($user, 99, 'uncertain');
    mail_job($user, 100, 'ineligible');
    mail_job($user, 99, 'eligible', 'external', ['deadline' => '2020-01-01']);
    mail_job($other, 100);
    $archived = mail_job($user);
    $db->prepare("UPDATE job_listings SET status = 'archived' WHERE id = ?")->execute([$archived]);
    $submitted = mail_job($user);
    $submittedApplication = $applications->create(
        $user,
        (new JobRepository())->get($user, $submitted),
    );
    $applications->update($user, $submittedApplication, [
        'status' => 'submitted',
        'submitted_at' => '2020-01-01 12:00:00',
    ]);

    mail_check(
        'missing SMTP credentials keep the alert pending without queuing a failing mail task',
        function () use ($smtp, $service, $user, $job, $alerts): void {
            mail_same(false, $smtp->configured());
            mail_same(0, $service->queueForUser($user));
            mail_same('pending', $alerts->forJob($user, $job)['status']);
            mail_same(
                1,
                (int) Database::connection()
                    ->query('SELECT COUNT(*) FROM manual_job_alerts WHERE user_id = ' . $user)
                    ->fetchColumn(),
            );
        },
    );
    $alertId = (int) $alerts->forJob($user, $job)['id'];
    mail_check(
        'only eligible open unsubmitted manual jobs meeting the personal threshold are selected',
        function () use ($alerts, $user, $job): void {
            mail_same(
                [$job],
                array_map('intval', array_column($alerts->candidates($user, 80), 'id')),
            );
            mail_same([], $alerts->candidates($user, 99));
        },
    );
    mail_check('pending alerts do not attempt mail while SMTP is missing', function () use (
        $service,
        $user,
        $alertId,
        $recording,
        $alerts,
    ): void {
        $service->deliver($user, $alertId);
        mail_same(0, $recording->calls);
        mail_same('pending', $alerts->get($user, $alertId)['status']);
    });
    foreach (
        [
            'SMTP_HOST=smtp.gmail.com',
            'SMTP_PORT=587',
            'SMTP_ENCRYPTION=tls',
            'SMTP_USERNAME=sender@example.test',
            'SMTP_PASSWORD=fixture-app-password',
            'SMTP_FROM_EMAIL=',
            'SMTP_FROM_NAME=JobFlow',
            'APP_URL=http://localhost/Synapse',
        ]
        as $value
    ) {
        putenv($value);
    }
    mail_check(
        'SMTP becomes ready once sender email and app password are supplied',
        fn() => mail_same(true, $smtp->configured()),
    );
    mail_check(
        'Gmail SMTP requires a full login email even when the From address is valid',
        function () use ($smtp): void {
            putenv('SMTP_FROM_EMAIL=sender@example.test');
            putenv('SMTP_USERNAME=Display Name');
            mail_same(false, $smtp->configured());
            putenv('SMTP_USERNAME=sender@example.test');
            mail_same(true, $smtp->configured());
            putenv('SMTP_FROM_EMAIL=');
        },
    );
    mail_check(
        'each user/job has one durable alert and one active background task',
        function () use ($service, $alerts, $user, $job, $alertId, $db): void {
            $service->queueForUser($user);
            $service->queueForUser($user);
            mail_same($alertId, $alerts->ensure($user, $job));
            $query = $db->prepare(
                "SELECT COUNT(*) FROM background_tasks WHERE user_id = ? AND type = 'manual_job_alert' AND status = 'pending'",
            );
            $query->execute([$user]);
            mail_same(1, (int) $query->fetchColumn());
        },
    );
    mail_check('alert reads and inserts cannot cross account ownership', function () use (
        $alerts,
        $service,
        $other,
        $job,
        $alertId,
    ): void {
        mail_denied(fn() => $alerts->get($other, $alertId));
        mail_denied(fn() => $alerts->ensure($other, $job));
        mail_denied(fn() => $service->deliver($other, $alertId));
    });
    mail_check(
        'free accounts and Manual or paused modes do not send or queue these alerts',
        function () use ($service, $settings, $alerts, $user, $other, $alertId, $recording): void {
            mail_same(0, $service->queueForUser($other));
            foreach (
                [
                    ['mode' => 'manual'],
                    ['mode' => 'approval'],
                    ['mode' => 'automatic', 'paused' => true],
                ]
                as $configuration
            ) {
                $settings->save(
                    $user,
                    'automation',
                    array_replace($settings->get($user, 'automation'), $configuration),
                );
                mail_same(0, $service->queueForUser($user));
                $service->deliver($user, $alertId);
                mail_same(0, $recording->calls);
                mail_same('pending', $alerts->get($user, $alertId)['status']);
            }
            $settings->save(
                $user,
                'automation',
                array_replace($settings->get($user, 'automation'), [
                    'mode' => 'automatic',
                    'paused' => false,
                ]),
            );
        },
    );
    mail_check(
        'PHPMailer sends the opportunity to the account email with safe links and multipart content',
        function () use (
            $service,
            $user,
            $alertId,
            $recording,
            $repository,
            $applications,
            $application,
            $alerts,
        ): void {
            $service->deliver($user, $alertId);
            mail_same(1, $recording->calls);
            mail_same(
                [[$repository->byId($user)['email'], 'Mail Fixture']],
                $recording->getToAddresses(),
            );
            mail_same('smtp', $recording->Mailer);
            mail_same(true, $recording->SMTPAuth);
            mail_same('tls', $recording->SMTPSecure);
            mail_same(0, $recording->SMTPDebug);
            mail_same(true, $recording->SMTPOptions['ssl']['verify_peer']);
            mail_same(false, $recording->SMTPOptions['ssl']['allow_self_signed']);
            mail_same(true, str_contains($recording->mime, 'multipart/alternative'));
            mail_same(true, str_contains($recording->AltBody, 'Nothing has been submitted'));
            mail_same(
                true,
                str_contains(
                    $recording->Body,
                    'http://localhost/Synapse/applications/' . $application,
                ),
            );
            mail_same(false, str_contains($recording->Body, '<em>Employer</em>'));
            mail_same(true, str_contains($recording->Body, '&lt;em&gt;Employer&lt;/em&gt;'));
            mail_same('sent', $alerts->get($user, $alertId)['status']);
            mail_same($recording->MessageID, $alerts->get($user, $alertId)['message_id']);
            mail_same([], $recording->getAttachments());
        },
    );
    mail_check(
        'an email alert leaves the application status, submission record and daily limit untouched',
        function () use (
            $applications,
            $user,
            $application,
            $originalApplication,
            $settings,
        ): void {
            mail_same($originalApplication, $applications->get($user, $application));
            mail_same(0, $applications->sentToday($user));
            mail_same(1, $settings->get($user, 'automation')['daily_limit']);
        },
    );
    mail_check(
        'successful alerts are never sent again after repeated scheduling or delivery calls',
        function () use ($service, $user, $alertId, $recording): void {
            mail_same(0, $service->queueForUser($user));
            $service->deliver($user, $alertId);
            $service->deliver($user, $alertId);
            mail_same(1, $recording->calls);
        },
    );
    mail_check(
        'automation skips the top website job and queues eligible email candidates in score order',
        function () use ($user, $applications, $db, $application): void {
            $ids = [];
            foreach (
                [[86, 'eligible'], [92, 'eligible'], [79, 'eligible'], [99, 'uncertain']]
                as [$score, $eligibility]
            ) {
                $job = mail_job($user, $score, $eligibility, 'email');
                $id = $applications->create($user, (new JobRepository())->get($user, $job));
                $applications->update($user, $id, ['status' => 'approved']);
                $ids[] = $id;
            }
            mail_same(2, (new AutomationService())->queueApprovedApplications($user));
            $query = $db->prepare(
                "SELECT payload_json FROM background_tasks WHERE user_id = ? AND type = 'submit_application' ORDER BY id",
            );
            $query->execute([$user]);
            $queued = array_map(
                fn($payload) => json_decode($payload, true)['application_id'],
                $query->fetchAll(PDO::FETCH_COLUMN),
            );
            mail_same([$ids[1], $ids[0]], $queued);
            mail_same(false, in_array($application, $queued, true));
        },
    );
    mail_check(
        'delivery rechecks current eligibility instead of sending a stale queued recommendation',
        function () use ($user, $service, $alerts, $db, $recording): void {
            $job = mail_job($user);
            $id = $alerts->ensure($user, $job);
            $db->prepare(
                "UPDATE match_results SET eligibility = 'ineligible' WHERE user_id = ? AND job_id = ?",
            )->execute([$user, $job]);
            $service->deliver($user, $id);
            mail_same(1, $recording->calls);
            mail_same('pending', $alerts->get($user, $id)['status']);
        },
    );
    mail_check(
        'expired or already submitted vacancies are rechecked immediately before delivery',
        function () use ($user, $service, $alerts, $db, $recording, $applications): void {
            foreach (['expired', 'submitted'] as $change) {
                $job = mail_job($user);
                $id = $alerts->ensure($user, $job);
                if ($change === 'expired') {
                    $db->prepare(
                        "UPDATE job_listings SET deadline = '2020-01-01' WHERE id = ?",
                    )->execute([$job]);
                } else {
                    $application = $applications->create(
                        $user,
                        (new JobRepository())->get($user, $job),
                    );
                    $applications->update($user, $application, [
                        'status' => 'submitted',
                        'submitted_at' => '2020-01-01 12:00:00',
                    ]);
                }
                $service->deliver($user, $id);
                mail_same('pending', $alerts->get($user, $id)['status']);
            }
            mail_same(1, $recording->calls);
        },
    );
    mail_check(
        'unconfirmed SMTP failure is sanitized and is not automatically replayed',
        function () use ($user, $service, $alerts, $recording): void {
            $job = mail_job($user);
            $id = $alerts->ensure($user, $job);
            $recording->fail = true;
            try {
                $service->deliver($user, $id);
                throw new RuntimeException('Expected SMTP failure.');
            } catch (HttpException $exception) {
                mail_same(502, $exception->status);
                mail_same(false, str_contains($exception->getMessage(), 'fixture-app-password'));
            }
            mail_same('failed', $alerts->get($user, $id)['status']);
            mail_same(
                false,
                str_contains($alerts->get($user, $id)['last_error'], 'fixture-app-password'),
            );
            $recording->fail = false;
            $service->deliver($user, $id);
            mail_same(2, $recording->calls);
            mail_same(0, $service->queueForUser($user));
        },
    );
    mail_check(
        'a crashed notification lease is held for review without retrying delivery',
        function () use ($user, $alerts, $queue, $db): void {
            $id = $alerts->ensure($user, mail_job($user));
            $alerts->reserve($user, $id, '<interrupted-fixture@jobflow.local>');
            $taskId = $queue->enqueue($user, 'manual_job_alert', ['alert_id' => $id]);
            $db->prepare(
                "UPDATE background_tasks SET status = 'running', attempts = 1, reserved_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = ?",
            )->execute([$taskId]);
            $queue->recoverStale();
            mail_same('failed', $alerts->get($user, $id)['status']);
            mail_same('failed', $queue->get($user, $taskId)['status']);
        },
    );
    mail_check('SMTP rejects unencrypted or injected server configuration', function () use (
        $smtp,
    ): void {
        putenv('SMTP_ENCRYPTION=none');
        mail_same(false, $smtp->configured());
        putenv('SMTP_ENCRYPTION=ssl');
        putenv('SMTP_PORT=465');
        mail_same(true, $smtp->configured());
        putenv('SMTP_HOST=smtp.gmail.com;evil.example');
        mail_same(false, $smtp->configured());
        putenv('SMTP_HOST=smtp.gmail.com');
        putenv('SMTP_PORT=70000');
        mail_same(false, $smtp->configured());
    });
    mail_check(
        'a mock delivery stays outside real application counts and cannot be submitted again',
        function () use ($user, $applications): void {
            $jobs = new JobRepository();
            $job = mail_job($user);
            $jobs->status($user, $job, 'archived');
            $id = $applications->create($user, $jobs->get($user, $job));
            mail_denied(
                fn() => (new \App\Services\ApplicationService())->transition(
                    $user,
                    $id,
                    'test_sent',
                ),
                422,
            );
            $applications->update($user, $id, [
                'status' => 'test_sent',
                'send_key' => hash('sha256', 'mock-delivery-fixture-' . $id),
                'provider_message_id' => 'fixture-test-message',
                'confirmation' => 'Fixture test email accepted; not a real job application.',
            ]);
            mail_same(0, $applications->sentToday($user));
            mail_same(null, $applications->get($user, $id)['submitted_at']);
            mail_denied(
                fn() => (new \App\Services\ApplicationService())->transition(
                    $user,
                    $id,
                    'submitted',
                    'Test',
                ),
                422,
            );
            mail_denied(fn() => (new \App\Services\ApplicationService())->send($user, $id), 422);
        },
    );
    mail_check(
        'SMTP builds a test application with two private PDF attachments and clears them for the next email',
        function () use ($user): void {
            putenv('SMTP_PORT=587');
            putenv('SMTP_ENCRYPTION=tls');
            $recording = new RecordingMailer(true);
            $smtp = new SmtpMailer($recording);
            $repository = new \App\Repositories\DocumentRepository();
            $exporter = new \App\Services\Documents\Exporter();
            $documents = [];
            foreach (['CV', 'Cover letter'] as $name) {
                $id = $exporter->letter(
                    $user,
                    'Synthetic application fixture.',
                    'pdf',
                    $name . ' fixture',
                );
                $documents[] = $repository->get($user, $id);
            }
            try {
                $smtp->send(
                    'recipient@example.test',
                    'Fixture recipient',
                    '[TEST] Application',
                    'Synthetic email.',
                    '<p>Synthetic email.</p>',
                    '<mock-attachments@jobflow.local>',
                    $documents,
                );
                mail_same(2, count($recording->getAttachments()));
                mail_same(true, str_contains($recording->mime, 'multipart/mixed'));
                foreach ($documents as $document) {
                    mail_same(
                        true,
                        str_contains($recording->mime, 'filename="' . $document['name'] . '"'),
                    );
                    $path = (new \App\Services\Documents\DocumentService())->path($document);
                    mail_same(
                        true,
                        str_contains(
                            $recording->mime,
                            rtrim(chunk_split(base64_encode(file_get_contents($path)))),
                        ),
                    );
                }
                $smtp->send(
                    'recipient@example.test',
                    'Fixture recipient',
                    'Next notification',
                    'No attachments.',
                    '<p>No attachments.</p>',
                    '<mock-notification@jobflow.local>',
                );
                mail_same([], $recording->getAttachments());
                mail_same(false, str_contains($recording->mime, 'CV fixture.pdf'));
            } finally {
                foreach ($documents as $document) {
                    unlink((new \App\Services\Documents\DocumentService())->path($document));
                }
            }
        },
    );
} finally {
    putenv('SMTP_PASSWORD=');
    foreach ($users as $userId) {
        Database::connection()
            ->prepare('DELETE FROM users WHERE id = ?')
            ->execute([$userId]);
    }
}
echo "\n$passed SMTP/manual application checks passed. No external email was sent.\n";
