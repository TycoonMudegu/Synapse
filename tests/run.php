<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AiService;
use App\Services\AI\CompatibleProvider;
use App\Services\AI\Provider;
use App\Services\AI\Schema;
use App\Services\ApplicationService;
use App\Services\Discovery\DiscoveryService;
use App\Services\Discovery\PublicContent;
use App\Services\Documents\DocumentService;
use App\Services\Documents\Exporter;
use App\Services\Documents\PreparationService;
use App\Services\JobService;
use App\Services\Mail\GmailService;
use App\Services\ProfileService;
use App\Services\Security\Crypto;
use App\Services\Security\Csrf;
use App\Services\Security\UrlGuard;
use App\Services\Security\Validator;
use App\Services\Worker;

ob_start();
session_save_path(BASE_PATH . '/storage/sessions');
session_start();
$passed = 0;
$failed = 0;
$fixtures = [];

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    }
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true),
        );
    }
}

function rejects(callable $operation, string $class = HttpException::class): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('Expected rejection did not occur.');
}

check('authenticated encryption round trip and random nonces', function (): void {
    $first = Crypto::encrypt('test credential');
    same('test credential', Crypto::decrypt($first));
    same(false, $first === Crypto::encrypt('test credential'));
});
check('tampered encrypted credentials are rejected', function (): void {
    $ciphertext = base64_decode(Crypto::encrypt('test credential'));
    $ciphertext[30] = chr(ord($ciphertext[30]) ^ 1);
    rejects(fn() => Crypto::decrypt(base64_encode($ciphertext)), RuntimeException::class);
});
check('CSRF rejects missing and incorrect tokens', function (): void {
    rejects(fn() => Csrf::validate(''));
    rejects(fn() => Csrf::validate(str_repeat('a', 64)));
    Csrf::validate(Csrf::token());
});
foreach (
    [
        '127.0.0.1',
        '10.0.0.2',
        '172.16.0.1',
        '192.168.1.1',
        '169.254.169.254',
        '0.0.0.0',
        '224.0.0.1',
        '::1',
        'fc00::1',
        'fe80::1',
        '::ffff:127.0.0.1',
        '100.100.100.200',
        '198.18.0.1',
        '2001::1',
        '2001:2::1',
        '2001:10::1',
        '2001:db8::1',
        '2001:0db8:0000:0000:0000:0000:0000:0001',
        '2002:c000:201::1',
        '3fff:fff::1',
    ]
    as $ip
) {
    check('SSRF rejects non-public address ' . $ip, fn() => same(false, UrlGuard::publicIp($ip)));
}
check('public Google and Cloudflare IPv6 addresses are accepted', function (): void {
    same(true, UrlGuard::publicIp('2001:4860:4840:400::'));
    same(true, UrlGuard::publicIp('2606:4700:4700::1111'));
});
foreach (
    [
        'file:///etc/passwd',
        'http://example.com',
        'https://127.0.0.1',
        'https://user:pass@example.com',
        'https://example.com:8443',
        "https://example.com/\r\nHost:localhost",
        'https://[::1]',
    ]
    as $url
) {
    check(
        'SSRF rejects unsafe URL ' . str_replace(["\r", "\n"], '', $url),
        fn() => rejects(fn() => UrlGuard::validate($url)),
    );
}
check(
    'job URLs canonicalise tracking fields and query order',
    fn() => same(
        'https://example.com/job?a=1&b=2',
        UrlGuard::canonical('https://EXAMPLE.com/job/?b=2&utm_source=test&a=1#top'),
    ),
);
check('robots disallow and longest allow are respected', function (): void {
    $public = new PublicContent();
    $robots = "User-agent: *\nDisallow: /private\nAllow: /private/open\n";
    same(false, $public->allowed($robots, '/private/job'));
    same(true, $public->allowed($robots, '/private/open/job'));
    same(true, $public->allowed($robots, '/careers'));
});
check('HTML imports remove scripts and navigation', function (): void {
    $text = (new PublicContent())->text(
        '<html><nav>Not job content</nav><script>bad()</script><main><h1>Vacancy title</h1><p>Role description</p></main></html>',
    );
    same(false, str_contains($text, 'bad()'));
    same(false, str_contains($text, 'Not job content'));
    same(true, str_contains($text, 'Vacancy title'));
});
check('schema validation rejects incorrect types, missing and unknown fields', function (): void {
    Schema::validate(['status' => 'connected'], Schema::forTask('test'));
    rejects(fn() => Schema::validate(['status' => 1], Schema::forTask('test')));
    rejects(fn() => Schema::validate([], Schema::forTask('test')));
    rejects(
        fn() => Schema::validate(
            ['status' => 'connected', 'secret' => true],
            Schema::forTask('test'),
        ),
    );
});
check(
    'schema validation rejects match scores above 100',
    fn() => rejects(
        fn() => Schema::validate(101, ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]),
    ),
);
check('tailoring only selects existing entries and retains omitted facts', function (): void {
    $service = new PreparationService();
    same(['B', 'A', 'C'], $service->select(['A', 'B', 'C'], [1]));
    rejects(fn() => $service->select(['A'], [3]));
    rejects(fn() => $service->select(['A'], [0, 0]));
});
check('calendar validation rejects impossible dates', function (): void {
    same('2028-02-29', Validator::date('2028-02-29', 'deadline'));
    rejects(fn() => Validator::date('2026-02-30', 'deadline'));
});
check('MIME construction encodes body and blocks header injection', function (): void {
    $service = new GmailService();
    $mime = $service->mime(
        'sender@example.com',
        'recipient@example.com',
        'Fixture subject',
        'Fixture body',
        [],
        'fixture',
    );
    same(true, str_contains($mime, 'Message-ID: <fixture@jobflow.local>'));
    same(true, str_contains($mime, base64_encode('Fixture body')));
    rejects(
        fn() => $service->mime(
            'sender@example.com',
            'recipient@example.com',
            "Subject\r\nBcc: other@example.com",
            'body',
            [],
            null,
        ),
    );
});
check('job normalisation preserves unknown salary and deadline', function (): void {
    $job = (new JobService())->normalise([
        'title' => 'Fixture role',
        'employer' => 'Fixture organisation',
        'description' => 'Test fixture only.',
    ]);
    same(null, $job['salary_min']);
    same(null, $job['salary_max']);
    same(null, $job['deadline']);
    same(null, $job['currency']);
    $emptyPoster = (new JobService())->normalise([
        'title' => 'Fixture',
        'employer' => 'Fixture',
        'description' => 'Fixture',
        'poster_document_id' => '',
    ]);
    same(null, $emptyPoster['poster_document_id']);
});
check(
    'email applications require a real recipient',
    fn() => rejects(
        fn() => (new JobService())->normalise([
            'title' => 'Fixture',
            'employer' => 'Fixture',
            'description' => 'Fixture',
            'application_method' => 'email',
        ]),
    ),
);

if (in_array('--unit', $argv, true)) {
    echo "\n$passed passed; $failed failed. Database tests explicitly skipped (--unit).\n";
    ob_end_flush();
    exit($failed ? 1 : 0);
}

try {
    require BASE_PATH . '/scripts/migrate.php';
    $db = Database::connection();
    $users = new UserRepository();
    $suffix = bin2hex(random_bytes(6));
    $userId = $users->create(
        'Fixture Applicant',
        'fixture-' . $suffix . '@example.test',
        'fixture-password-12345',
    );
    $otherId = $users->create(
        'Other Fixture',
        'other-' . $suffix . '@example.test',
        'fixture-password-12345',
    );
    $fixtures = [$userId, $otherId];
    $db->prepare("INSERT INTO memberships (user_id, plan) VALUES (?, 'lifetime')")->execute([$userId]);
    $profileInput = [
        'full_name' => 'Fixture Applicant',
        'email' => 'fixture-' . $suffix . '@example.test',
        'phone' => '+254700000000',
        'location' => 'Nairobi, Kenya',
        'summary' => 'Career profile used only for automated tests.',
        'skills' => 'PHP, SQL',
        'employment' => [
            [
                'position' => 'Fixture Engineer',
                'employer' => 'Fixture Employer',
                'start_date' => '2020',
                'end_date' => '2024',
                'achievements' => 'Fixture record.',
            ],
        ],
        'education' => [
            [
                'qualification' => 'Fixture Qualification',
                'institution' => 'Fixture Institution',
                'completion_year' => '2020',
            ],
        ],
        'verified' => true,
    ];
    check('career profile and related records persist transactionally', function () use (
        $userId,
        $profileInput,
    ): void {
        (new ProfileService())->save($userId, $profileInput);
        $profile = (new ProfileRepository())->get($userId);
        same(['PHP', 'SQL'], $profile['skills']);
        same('Fixture Employer', $profile['employment'][0]['employer']);
        same(true, $profile['verified_at'] !== null);
    });
    $jobInput = [
        'title' => 'Fixture Vacancy ' . $suffix,
        'employer' => 'Fixture Organisation',
        'description' => 'Automated test fixture, never production data.',
        'location' => 'Nairobi, Kenya',
        'original_url' => 'https://example.com/fixture-' . $suffix . '?utm_source=test',
        'application_method' => 'email',
        'application_email' => 'applications@example.test',
        'verified' => true,
    ];
    $jobResult = (new JobService())->create($userId, $jobInput);
    $jobId = $jobResult['id'];
    check('jobs save and duplicate canonical URLs return existing vacancy', function () use (
        $userId,
        $jobInput,
        $jobId,
    ): void {
        $again = (new JobService())->create($userId, [
            ...$jobInput,
            'original_url' =>
                'https://example.com/fixture-' .
                substr($jobInput['title'], -12) .
                '?utm_campaign=other',
        ]);
        same($jobId, $again['id']);
        same(true, $again['duplicate']);
    });
    check(
        'job ownership prevents access across users',
        fn() => rejects(fn() => (new JobRepository())->get($otherId, $jobId)),
    );
    check('dashboard metrics are derived from saved records', function () use ($userId): void {
        $stats = (new JobRepository())->dashboard($userId);
        same(1, (int) $stats['jobs']['total']);
        same(0, (int) $stats['applications']['submitted']);
    });
    check(
        'search input is bound rather than interpolated',
        fn() => same([], (new JobRepository())->list($userId, ['q' => "' OR 1=1 --"])),
    );
    $applicationId = (new ApplicationRepository())->create(
        $userId,
        (new JobRepository())->get($userId, $jobId),
    );
    check(
        'application creation is idempotent for each user/job',
        fn() => same(
            $applicationId,
            (new ApplicationRepository())->create(
                $userId,
                (new JobRepository())->get($userId, $jobId),
            ),
        ),
    );
    check(
        'applications enforce ownership',
        fn() => rejects(fn() => (new ApplicationRepository())->get($otherId, $applicationId)),
    );
    check('unreviewed applications cannot be approved', function () use (
        $userId,
        $applicationId,
    ): void {
        $service = new ApplicationService();
        rejects(fn() => $service->transition($userId, $applicationId, 'approved'));
        $service->transition($userId, $applicationId, 'ready_for_review');
        rejects(fn() => $service->transition($userId, $applicationId, 'approved'));
    });
    $profile = (new ProfileRepository())->get($userId);
    $cvPdf = (new Exporter())->cv($userId, $profile, 'pdf', 'Fixture CV');
    $cvDocx = (new Exporter())->cv($userId, $profile, 'docx', 'Fixture CV');
    $letterId = (new Exporter())->letter($userId, 'Fixture cover letter.', 'pdf', 'Fixture letter');
    check('generated PDF is a real PDF with extractable text', function () use (
        $userId,
        $cvPdf,
    ): void {
        $document = (new DocumentRepository())->get($userId, $cvPdf);
        $path = (new DocumentService())->path($document);
        same('%PDF-', substr(file_get_contents($path), 0, 5));
        same(
            true,
            str_contains(
                (new \Smalot\PdfParser\Parser())->parseFile($path)->getText(),
                'Fixture Applicant',
            ),
        );
    });
    check('generated DOCX round trips through native XML extraction', function () use (
        $userId,
        $cvDocx,
    ): void {
        $document = (new DocumentRepository())->get($userId, $cvDocx);
        $text = (new DocumentService())->docxText((new DocumentService())->path($document));
        same(true, str_contains($text, 'Fixture Applicant'));
        same(true, str_contains($text, 'Fixture Employer'));
    });
    check(
        'documents enforce user ownership',
        fn() => rejects(fn() => (new DocumentRepository())->get($otherId, $cvPdf)),
    );
    check('only one master CV is selected per user', function () use (
        $userId,
        $cvPdf,
        $cvDocx,
    ): void {
        $documents = new DocumentRepository();
        $documents->addCv($userId, $cvPdf);
        $documents->addCv($userId, $cvDocx);
        $documents->master($userId, $cvPdf);
        $documents->master($userId, $cvDocx);
        same(
            1,
            count(
                array_filter($documents->all($userId), fn($item) => (int) $item['is_master'] === 1),
            ),
        );
    });
    check('reviewed attachments allow approval but Manual mode blocks sending', function () use (
        $userId,
        $applicationId,
        $cvPdf,
        $letterId,
    ): void {
        $repository = new ApplicationRepository();
        $repository->update($userId, $applicationId, [
            'cv_document_id' => $cvPdf,
            'letter_document_id' => $letterId,
            'documents_verified_at' => date('Y-m-d H:i:s'),
            'email_body' => 'Fixture application.',
        ]);
        $service = new ApplicationService();
        $service->transition($userId, $applicationId, 'approved');
        rejects(fn() => $service->send($userId, $applicationId));
        same('approved', $repository->get($userId, $applicationId)['status']);
        same(null, $repository->get($userId, $applicationId)['send_key']);
    });
    check(
        'application attachments cannot be deleted',
        fn() => rejects(fn() => (new DocumentRepository())->delete($userId, $cvPdf)),
    );
    check('job edits increment revision and revoke existing document approvals', function () use (
        $userId,
        $jobId,
        $applicationId,
        $jobInput,
    ): void {
        $jobs = new JobRepository();
        $before = $jobs->get($userId, $jobId);
        $jobs->edit(
            $userId,
            $jobId,
            (new JobService())->normalise([
                ...$jobInput,
                'requirements' => 'Updated fixture requirement.',
            ]),
        );
        same((int) $before['revision'] + 1, (int) $jobs->get($userId, $jobId)['revision']);
        $application = (new ApplicationRepository())->get($userId, $applicationId);
        same('ready_for_review', $application['status']);
        same(null, $application['documents_verified_at']);
        (new ApplicationRepository())->update($userId, $applicationId, [
            'documents_verified_at' => date('Y-m-d H:i:s'),
        ]);
        (new ApplicationService())->transition($userId, $applicationId, 'approved');
    });
    check('external submission requires confirmation', function () use (
        $userId,
        $applicationId,
    ): void {
        $service = new ApplicationService();
        $service->transition($userId, $applicationId, 'awaiting_external_action');
        rejects(fn() => $service->transition($userId, $applicationId, 'submitted'));
        $service->transition($userId, $applicationId, 'submitted', 'Fixture external confirmation');
        same('submitted', (new ApplicationRepository())->get($userId, $applicationId)['status']);
    });
    check(
        'invalid backwards state transitions are rejected',
        fn() => rejects(
            fn() => (new ApplicationService())->transition($userId, $applicationId, 'draft'),
        ),
    );
    check('profile edits invalidate stored match results', function () use (
        $userId,
        $jobId,
        $profileInput,
    ): void {
        $profile = (new ProfileRepository())->get($userId);
        (new JobRepository())->saveMatch(
            $userId,
            $jobId,
            ['score' => 90, 'eligibility' => 'uncertain'],
            $profile['updated_at'],
        );
        same(90, (int) (new JobRepository())->get($userId, $jobId)['score']);
        (new ProfileService())->save($userId, $profileInput);
        same(null, (new JobRepository())->get($userId, $jobId)['score']);
    });
    check('profile revisions change even for immediate repeated saves', function () use (
        $userId,
        $profileInput,
    ): void {
        $before = (new ProfileRepository())->get($userId);
        (new ProfileService())->save($userId, $profileInput);
        $first = (new ProfileRepository())->get($userId);
        (new ProfileService())->save($userId, $profileInput);
        $second = (new ProfileRepository())->get($userId);
        same((int) $before['revision'] + 1, (int) $first['revision']);
        same((int) $first['revision'] + 1, (int) $second['revision']);
    });
    check('account request limits reject excess mutations', function () use ($userId): void {
        $limits = new \App\Repositories\RateLimitRepository();
        $limits->check($userId, 'fixture-limit', 2);
        $limits->check($userId, 'fixture-limit', 2);
        rejects(fn() => $limits->check($userId, 'fixture-limit', 2));
    });
    check('remember tokens are consumed only once', function () use ($userId): void {
        $repository = new UserRepository();
        $selector = bin2hex(random_bytes(12));
        $repository->remember($userId, $selector, hash('sha256', 'fixture-validator'));
        same($userId, (int) $repository->consumeRemember($selector)['user_id']);
        same(null, $repository->consumeRemember($selector));
    });
    check('settings persist and reject implicit Automatic mode', function () use ($userId): void {
        $service = new \App\Services\SettingsService();
        rejects(fn() => $service->save($userId, 'automation', ['mode' => 'automatic']));
        $service->save($userId, 'automation', [
            'mode' => 'automatic',
            'confirm_automatic' => true,
            'allow_email' => true,
        ]);
        same('automatic', (new SettingsRepository())->get($userId, 'automation')['mode']);
        $service->save($userId, 'automation', ['mode' => 'manual']);
    });
    check('sources remain inactive until successfully tested', function () use ($userId): void {
        $sources = new SourceRepository();
        $id = $sources->save($userId, [
            'name' => 'Fixture feed',
            'adapter' => 'rss',
            'url' => 'https://example.com/fixture.rss',
            'permission_confirmed' => true,
        ]);
        same(0, (int) $sources->get($userId, $id)['enabled']);
        rejects(fn() => $sources->toggle($userId, $id, true));
        $sources->health($userId, $id, true);
        same(1, (int) $sources->get($userId, $id)['enabled']);
        $sources->save(
            $userId,
            [
                'name' => 'Edited fixture',
                'adapter' => 'rss',
                'url' => 'https://example.com/edited.rss',
                'permission_confirmed' => true,
            ],
            $id,
        );
        same('untested', $sources->get($userId, $id)['health']);
        same(0, (int) $sources->get($userId, $id)['enabled']);
    });
    check('queue deduplicates active tasks and separates users', function () use (
        $userId,
        $otherId,
    ): void {
        $queue = new QueueRepository();
        $first = $queue->enqueue($userId, 'verify_deadlines');
        same($first, $queue->enqueue($userId, 'verify_deadlines'));
        rejects(fn() => $queue->get($otherId, $first));
        $task = $queue->claim();
        same($first, (int) $task['id']);
        same('running', $queue->get($userId, $first)['status']);
        $queue->complete($task);
        same('completed', $queue->get($userId, $first)['status']);
        same(false, $first === $queue->enqueue($userId, 'verify_deadlines'));
    });
    check('worker processes a real deadline task', function () use ($userId): void {
        same(true, (new Worker())->tick());
        same(
            'completed',
            (new QueueRepository())->latestRun($userId, 'verify_deadlines')['status'],
        );
    });
    check('queue retries failures with bounded attempts', function () use ($userId): void {
        $queue = new QueueRepository();
        $id = $queue->enqueue($userId, 'fixture_retry');
        $task = $queue->claim();
        $queue->fail($task, 'Fixture transient error');
        same('pending', $queue->get($userId, $id)['status']);
    });
    check('uncertain submission tasks are not automatically retried', function () use (
        $userId,
    ): void {
        $queue = new QueueRepository();
        $id = $queue->enqueue($userId, 'submit_application', ['application_id' => 999999]);
        $task = $queue->claim();
        $queue->fail($task, 'Fixture uncertain send');
        same('failed', $queue->get($userId, $id)['status']);
    });
    check('stale submission leases preserve send keys and require reconciliation', function () use (
        $userId,
        $applicationId,
        $db,
    ): void {
        $applications = new ApplicationRepository();
        $key = bin2hex(random_bytes(32));
        $applications->update($userId, $applicationId, [
            'status' => 'submitting',
            'send_key' => $key,
        ]);
        $queue = new QueueRepository();
        $id = $queue->enqueue($userId, 'submit_application', ['application_id' => $applicationId]);
        $task = $queue->claim();
        same($id, (int) $task['id']);
        $statement = $db->prepare(
            'UPDATE background_tasks SET reserved_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ? AND user_id = ?',
        );
        $statement->execute([$id, $userId]);
        $queue->recoverStale();
        same('failed', $queue->get($userId, $id)['status']);
        same('awaiting_external_action', $applications->get($userId, $applicationId)['status']);
        same($key, $applications->get($userId, $applicationId)['send_key']);
    });
    check('AI budget reservation uses configured model and records tokens', function () use (
        $userId,
    ): void {
        $settings = new SettingsRepository();
        $id = $settings->saveProvider($userId, 0, [
            'Fixture provider',
            'https://example.com/v1',
            Crypto::encrypt('fixture-only-key'),
            'fixture-model',
            json_encode(['max_tokens' => 100, 'input_per_million' => 1, 'output_per_million' => 2]),
        ]);
        $settings->save($userId, 'ai', ['monthly_limit_usd' => 1, 'screening_provider' => $id]);
        $provider = new class implements Provider {
            public function generate(array $configuration, array $messages, array $schema): array
            {
                same('fixture-model', $configuration['model']);
                same(true, str_contains($messages[0]['content'], 'UNTRUSTED DATA'));
                return [
                    'data' => ['status' => 'connected'],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5],
                ];
            }
        };
        same(
            ['status' => 'connected'],
            (new AiService($provider))->run($userId, 'test', ['request' => 'fixture']),
        );
        same(100, (int) $settings->usage($userId)[0]['input_tokens']);
        $settings->save($userId, 'ai', [
            'monthly_limit_usd' => 0.000001,
            'screening_provider' => $id,
        ]);
        rejects(fn() => (new AiService($provider))->run($userId, 'test', ['request' => 'fixture']));
    });
    check('compatible providers reject malformed structured responses', function (): void {
        $transport = new class extends HttpClient {
            public function json(
                string $method,
                string $url,
                array $data = [],
                array $headers = [],
                int $timeout = 45,
            ): array {
                return [
                    'choices' => [
                        [
                            'finish_reason' => 'stop',
                            'message' => ['content' => '{"status": "wrong"}'],
                        ],
                    ],
                ];
            }
        };
        rejects(
            fn() => (new CompatibleProvider($transport))->generate(
                [
                    'options_json' => '{}',
                    'endpoint' => 'https://example.com',
                    'model' => 'fixture',
                    'encrypted_key' => Crypto::encrypt('fixture'),
                ],
                [],
                Schema::forTask('test'),
            ),
        );
    });
} catch (Throwable $exception) {
    $failed++;
    echo 'Integration setup failed: ' . $exception->getMessage() . PHP_EOL;
} finally {
    if ($fixtures !== []) {
        foreach ($fixtures as $fixture) {
            foreach ((new DocumentRepository())->all($fixture) as $document) {
                $path = BASE_PATH . '/storage/private/' . $document['storage_path'];
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $delete = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$fixture]);
        }
    }
}
echo "\n$passed passed; $failed failed. All integration fixtures were isolated in the test database.\n";
ob_end_flush();
exit($failed ? 1 : 0);
