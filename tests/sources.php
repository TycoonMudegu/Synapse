<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\AlertRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\JobRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Repositories\UserRepository;
use App\Services\Discovery\DiscoveryService;
use App\Services\Discovery\EmailAlertAdapter;
use App\Services\Discovery\EmailAlertParser;
use App\Services\Discovery\LocationMatcher;
use App\Services\Discovery\MyJobMagAdapter;
use App\Services\Discovery\SourceCatalog;
use App\Services\Discovery\SourcePresetService;
use App\Services\Mail\GmailService;
use App\Services\Security\Crypto;

$passed = 0;
$failed = 0;
$userIds = [];

function source_check(string $name, callable $operation): void
{
    global $passed, $failed;
    try {
        $operation();
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        echo 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    }
}

function source_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true),
        );
    }
}

function source_rejects(callable $operation): void
{
    try {
        $operation();
    } catch (HttpException) {
        return;
    }
    throw new RuntimeException('Expected rejection did not occur.');
}

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Test fixture</title><item>
<title>Fixture role at Fixture organisation</title>
<position>Fixture role</position><company>Fixture organisation</company>
<location>Nairobi</location><industry>ICT</industry>
<link>https://www.myjobmag.co.ke/a_fields.php?id=999999999</link>
<expiryDate>Wed, 25 Nov 2026 00:00:00 GMT</expiryDate>
<description><![CDATA[<p>Fixture job &amp; requirements. This is only test data.</p>]]></description>
</item></channel></rss>
XML;
source_check('detailed MyJobMag fields import without AI or invented salary', function () use (
    $xml,
): void {
    $items = (new MyJobMagAdapter())->parse($xml, 25);
    source_same(1, count($items));
    source_same('Fixture role', $items[0]['structured']['title']);
    source_same('Fixture organisation', $items[0]['structured']['employer']);
    source_same('Nairobi', $items[0]['structured']['location']);
    source_same('2026-11-25', $items[0]['structured']['deadline']);
    source_same(false, array_key_exists('salary_min', $items[0]['structured']));
    source_same(
        'Fixture job & requirements. This is only test data.',
        $items[0]['structured']['description'],
    );
});
source_check('missing advertised deadlines stay unknown', function () use ($xml): void {
    $xml = preg_replace('#<expiryDate>.*?</expiryDate>#', '', $xml);
    source_same(null, (new MyJobMagAdapter())->parse($xml, 1)[0]['structured']['deadline']);
});
source_check('detailed feed rejects missing explicit employers and unsafe XML', function () use (
    $xml,
): void {
    source_rejects(
        fn() => (new MyJobMagAdapter())->parse(
            str_replace('<company>Fixture organisation</company>', '', $xml),
            1,
        ),
    );
    source_rejects(
        fn() => (new MyJobMagAdapter())->parse(
            '<!DOCTYPE rss [<!ENTITY test SYSTEM "file:///etc/passwd">]>' . $xml,
            1,
        ),
    );
    source_rejects(
        fn() => (new MyJobMagAdapter())->parse(
            str_replace('https://www.myjobmag.co.ke/', 'https://other.example/', $xml),
            1,
        ),
    );
});
source_check(
    'Kenya preference recognises Kenyan cities without relabelling Kampala',
    function (): void {
        source_same(true, LocationMatcher::matches('Nairobi', 'Kenya'));
        source_same(true, LocationMatcher::matches('Mombasa', 'kenya'));
        source_same(false, LocationMatcher::matches('Kampala, Uganda', 'Kenya'));
        source_same(false, LocationMatcher::matches('Busia, Uganda', 'Kenya'));
        source_same(false, LocationMatcher::matches('Nairobi', 'Kisumu'));
    },
);
source_check('sender domains are exact and reject display-name impersonation', function (): void {
    source_same(true, SourceCatalog::senderMatches('Fuzu <alerts@fuzu.com>', 'fuzu'));
    source_same(true, SourceCatalog::senderMatches('alerts@mail.fuzu.com', 'fuzu'));
    source_same(
        false,
        SourceCatalog::senderMatches('Fuzu <alerts@fuzu.com.attacker.example>', 'fuzu'),
    );
    source_same(false, SourceCatalog::senderMatches('fuzu.com <attacker@example.com>', 'fuzu'));
});
source_check(
    'HTML alert links survive text conversion without executable content',
    function (): void {
        $parser = new EmailAlertParser();
        $text = $parser->htmlToText(
            '<script>malicious()</script><p>Fixture <a href="https://www.brightermonday.co.ke/listings/fixture-role">view vacancy</a></p>',
        );
        source_same(false, str_contains($text, 'malicious()'));
        source_same(
            ['https://www.brightermonday.co.ke/listings/fixture-role'],
            $parser->urls($text, 'brightermonday'),
        );
    },
);
source_check(
    'alert parser deduplicates vacancies and ignores non-job or foreign links',
    function (): void {
        $text =
            'https://www.brightermonday.co.ke/listings/fixture-role?utm_source=email ' .
            'https://www.brightermonday.co.ke/listings/fixture-role ' .
            'https://www.brightermonday.co.ke/unsubscribe ' .
            'https://www.brightermonday.co.ke.attacker.example/listings/fixture-role';
        source_same(
            ['https://www.brightermonday.co.ke/listings/fixture-role'],
            (new EmailAlertParser())->urls($text, 'brightermonday'),
        );
        source_same(
            1,
            count(
                (new EmailAlertParser())->urls(
                    'https://www.fuzu.com/job?filters%5Bjob_id%5D=999999999&utm_source=email',
                    'fuzu',
                ),
            ),
        );
    },
);

if (in_array('--unit', $argv, true)) {
    echo "$passed source checks passed; $failed failed. Database checks skipped.\n";
    exit($failed ? 1 : 0);
}

try {
    require BASE_PATH . '/scripts/migrate.php';
    $users = new UserRepository();
    $id = $users->create(
        'Source Fixture',
        'sources-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $userIds[] = $id;
    $otherId = $users->create(
        'Other Source Fixture',
        'sources-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $userIds[] = $otherId;
    $service = new SourcePresetService();
    $ids = $service->install($id, ['brightermonday', 'fuzu']);
    source_check('source presets are idempotent and stay inactive without Gmail', function () use (
        $id,
        $service,
        $ids,
    ): void {
        source_same($ids, $service->install($id, ['brightermonday', 'fuzu']));
        source_same([], (new SourceRepository())->enabled($id, 5));
        source_same('needs_connection', (new SourceRepository())->get($id, $ids['fuzu'])['health']);
    });
    source_check('email sources require an authorised Gmail read connection', function () use (
        $id,
        $ids,
    ): void {
        source_rejects(
            fn() => (new EmailAlertAdapter())->validate(
                (new SourceRepository())->get($id, $ids['fuzu']),
            ),
        );
        (new IntegrationRepository())->connect(
            $id,
            'fixture@example.test',
            Crypto::encrypt('{}'),
            'https://www.googleapis.com/auth/gmail.send',
        );
        (new SourcePresetService())->refreshEmailHealth($id);
        source_same('needs_permission', (new SourceRepository())->get($id, $ids['fuzu'])['health']);
    });
    (new IntegrationRepository())->connect(
        $id,
        'fixture@example.test',
        Crypto::encrypt(
            json_encode([
                'access_token' => 'fixture-only-token',
                'refresh_token' => 'fixture-only-refresh',
                'expires_at' => time() + 3600,
            ]),
        ),
        'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly',
    );
    $http = new class extends HttpClient {
        public bool $spoof = false;
        public bool $trackingOnly = false;
        public function json(
            string $method,
            string $url,
            array $data = [],
            array $headers = [],
            int $timeout = 45,
        ): array {
            if (str_contains($url, '/messages?')) {
                source_same(true, str_contains(urldecode($url), 'fuzu.com'));
                source_same(true, str_contains(urldecode($url), 'brightermonday.co.ke'));
                return [
                    'messages' => [
                        [
                            'id' => $this->spoof
                                ? 'spoof-fixture'
                                : ($this->trackingOnly
                                    ? 'tracking-fixture'
                                    : 'fixture-message'),
                        ],
                    ],
                ];
            }
            return [
                'id' => $this->spoof
                    ? 'spoof-fixture'
                    : ($this->trackingOnly
                        ? 'tracking-fixture'
                        : 'fixture-message'),
                'threadId' => 'fixture-thread',
                'internalDate' => (string) (time() * 1000),
                'labelIds' => ['INBOX'],
                'snippet' => 'Test job alert.',
                'payload' => [
                    'headers' => [
                        [
                            'name' => 'From',
                            'value' => $this->spoof
                                ? 'Fuzu <attacker@example.test>'
                                : 'Fuzu <alerts@fuzu.com>',
                        ],
                        ['name' => 'Subject', 'value' => 'Fixture job recommendations'],
                    ],
                    'mimeType' => 'multipart/alternative',
                    'parts' => [
                        [
                            'mimeType' => 'text/plain',
                            'body' => [
                                'data' => base64_encode(
                                    'Read the HTML version for your job recommendations.',
                                ),
                            ],
                        ],
                        [
                            'mimeType' => 'text/html',
                            'body' => [
                                'data' => rtrim(
                                    strtr(
                                        base64_encode(
                                            $this->trackingOnly
                                                ? '<p>Your job alert uses a tracking link: https://notifications.fuzu.com/click/example</p>'
                                                : '<p>Fixture role at Fixture organisation</p><a href="https://www.fuzu.com/job?filters%5Bjob_id%5D=999999999">View fixture vacancy</a>',
                                        ),
                                        '+/',
                                        '-_',
                                    ),
                                    '=',
                                ),
                            ],
                        ],
                    ],
                ],
            ];
        }
    };
    source_check(
        'Gmail imports source emails and creates one pending item per vacancy URL',
        function () use ($id, $ids, $http): void {
            source_same(1, (new GmailService($http))->sync($id));
            source_same(1, count((new IntegrationRepository())->inbox($id)));
            source_same(1, count((new AlertRepository())->pending($id, $ids['fuzu'], 25)));
            source_same('connected', (new SourceRepository())->get($id, $ids['fuzu'])['health']);
            source_same(0, (new GmailService($http))->sync($id));
            source_same(1, count((new AlertRepository())->pending($id, $ids['fuzu'], 25)));
        },
    );
    source_check(
        'emails with impersonated sender names are not imported as source alerts',
        function () use ($id, $http): void {
            $http->spoof = true;
            source_same(0, (new GmailService($http))->sync($id));
            source_same(1, count((new IntegrationRepository())->inbox($id)));
        },
    );
    source_check('alert reads and captures enforce account ownership', function () use (
        $id,
        $otherId,
        $ids,
    ): void {
        $messageId = (new IntegrationRepository())->messageId($id, 'fixture-message');
        source_same([], (new AlertRepository())->pending($otherId, $ids['fuzu'], 25));
        source_rejects(
            fn() => (new AlertRepository())->capture(
                $otherId,
                (new SourceRepository())->get($id, $ids['fuzu']),
                $messageId,
                'Fixture',
                ['https://www.fuzu.com/jobs/fixture'],
            ),
        );
    });
    source_check(
        'email adapter uses stored alerts without fetching employer websites',
        function () use ($id, $ids): void {
            $items = (new EmailAlertAdapter())->collect(
                (new SourceRepository())->get($id, $ids['fuzu']),
                25,
            );
            source_same(1, count($items));
            source_same(true, isset($items[0]['alert_item_id']));
            source_same(true, str_contains($items[0]['url'], 'job_id'));
        },
    );
    source_check(
        'missing AI leaves email vacancies retryable and creates no fake jobs',
        function () use ($id, $ids): void {
            source_same(0, (new DiscoveryService())->run($id));
            source_same([], (new JobRepository())->list($id));
            $counts = (new AlertRepository())->counts($id);
            source_same('error', $counts[0]['status']);
            source_same(1, (new AlertRepository())->retry($id, $ids['fuzu']));
        },
    );
    source_check('reinstalling presets preserves a disabled email source', function () use (
        $id,
        $ids,
        $service,
    ): void {
        (new SourceRepository())->toggle($id, $ids['fuzu'], false);
        $service->install($id, ['fuzu']);
        source_same(0, (int) (new SourceRepository())->get($id, $ids['fuzu'])['enabled']);
    });
    source_check('tracking-only job alerts stay in the inbox for manual review', function () use (
        $id,
        $ids,
        $http,
    ): void {
        $http->spoof = false;
        $http->trackingOnly = true;
        (new SourceRepository())->toggle($id, $ids['fuzu'], true);
        source_same(1, (new GmailService($http))->sync($id));
        $messages = (new IntegrationRepository())->inbox($id, ['type' => 'job_alert_needs_review']);
        source_same(1, count($messages));
        source_same(true, str_contains($messages[0]['body'], 'notifications.fuzu.com'));
        source_same(1, count((new AlertRepository())->pending($id, $ids['fuzu'], 25)));
    });
    source_check('discovery-only workers leave submission tasks untouched', function () use (
        $id,
    ): void {
        $queue = new \App\Repositories\QueueRepository();
        $submissionId = $queue->enqueue($id, 'submit_application', ['application_id' => 999999999]);
        $deadlineId = $queue->enqueue($id, 'verify_deadlines');
        source_same(true, (new \App\Services\Worker(['verify_deadlines']))->tick());
        source_same('pending', $queue->get($id, $submissionId)['status']);
        source_same('completed', $queue->get($id, $deadlineId)['status']);
    });
    source_check(
        'recent feed checks defer collection without making another request',
        function () use ($otherId): void {
            $repository = new SourceRepository();
            $sourceId = $repository->ensurePreset(
                $otherId,
                'myjobmag',
                SourceCatalog::get('myjobmag'),
            );
            $repository->health($otherId, $sourceId, true);
            try {
                (new DiscoveryService())->run($otherId);
                throw new RuntimeException('Expected rate-limit deferral.');
            } catch (HttpException $exception) {
                source_same(429, $exception->status);
            }
            source_same(
                'deferred',
                (new \App\Repositories\QueueRepository())->latestRun($otherId, 'discovery')[
                    'status'
                ],
            );
        },
    );
} catch (Throwable $exception) {
    $failed++;
    echo 'Source integration setup failed: ' . $exception->getMessage() . PHP_EOL;
} finally {
    foreach ($userIds as $id) {
        $statement = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
        $statement->execute([$id]);
    }
}
echo "$passed source checks passed; $failed failed. All fixtures used the test database.\n";
exit($failed ? 1 : 0);
