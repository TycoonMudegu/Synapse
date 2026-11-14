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
use App\Services\Discovery\KeywordMatcher;
use App\Services\Discovery\TechnologyRoles;
use App\Services\Discovery\MyJobMagAdapter;
use App\Services\Discovery\PublicContent;
use App\Services\Discovery\PublicJobParser;
use App\Services\Discovery\PublicJobsAdapter;
use App\Services\Discovery\SourceCatalog;
use App\Services\Discovery\SourceAdapter;
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

$publicUrl = 'https://www.brightermonday.co.ke/listings/fixture-php-role';
$publicGraph = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'WebPage', 'url' => $publicUrl, 'mainEntity' => ['@id' => '#vacancy']],
        [
            '@type' => 'JobPosting',
            '@id' => '#vacancy',
            'title' => 'PHP Developer',
            'hiringOrganization' => ['@id' => '#employer'],
            'description' => '<p>Test-only PHP and MySQL duties.</p><script>malicious()</script>',
            'jobLocation' => ['@id' => '#place'],
            'employmentType' => ['FULL_TIME'],
            'validThrough' => '2099-12-31T00:00:00Z',
        ],
        [
            '@type' => 'Organization',
            '@id' => '#employer',
            'name' => 'Public collector test fixture',
        ],
        ['@type' => 'Place', '@id' => '#place', 'address' => ['@id' => '#address']],
        [
            '@type' => 'PostalAddress',
            '@id' => '#address',
            'addressLocality' => 'Nairobi',
            'addressCountry' => 'KE',
        ],
    ],
];
$publicHtml = '<script type="application/ld+json">' . json_encode($publicGraph) . '</script>';
source_check(
    'public structured vacancies resolve graph references without AI or invented fields',
    function () use ($publicHtml, $publicUrl): void {
        $job = (new PublicJobParser())->job($publicHtml, $publicUrl);
        source_same('PHP Developer', $job['title']);
        source_same('Public collector test fixture', $job['employer']);
        source_same('Nairobi, Kenya', $job['location']);
        source_same('Test-only PHP and MySQL duties.', $job['description']);
        source_same('2099-12-31', $job['deadline']);
        source_same('full_time', $job['employment_type']);
        source_same('external', $job['application_method']);
        source_same(false, isset($job['salary_min']));
    },
);
source_check(
    'public links stay on the source host and exclude login, foreign and query endpoints',
    function (): void {
        $links = (new PublicJobParser())->links(
            '<a href="/listings/fixture-php-role?utm_source=mail">One</a>' .
                '<a href="https://www.brightermonday.co.ke/listings/fixture-php-role">Duplicate</a>' .
                '<a href="https://www.brightermonday.co.ke.attacker.example/listings/unsafe">Unsafe</a>' .
                '<a href="https://name:pass@www.brightermonday.co.ke/listings/unsafe">Credentials</a>' .
                '<a href="https://www.brightermonday.co.ke:8443/listings/unsafe">Port</a>' .
                '<a href="/api/jobs">API</a><a href="/account/login">Login</a>',
            'brightermonday',
        );
        source_same(['https://www.brightermonday.co.ke/listings/fixture-php-role'], $links);
        source_same(
            ['https://www.fuzu.com/kenya/jobs/fixture-role'],
            (new PublicJobParser())->links(
                '<a href="/kenya/jobs/fixture-role">Job</a><a href="/uganda/jobs/other">Other country</a><a href="/kenya/job?filters[job_id]=123">Query</a>',
                'fuzu',
            ),
        );
    },
);
source_check(
    'public parser rejects missing employer, invalid deadlines and ambiguous vacancies',
    function () use ($publicGraph, $publicUrl): void {
        $graph = $publicGraph;
        unset($graph['@graph'][2]['name']);
        source_rejects(
            fn() => (new PublicJobParser())->job(
                '<script type="application/ld+json">' . json_encode($graph) . '</script>',
                $publicUrl,
            ),
        );
        $graph = $publicGraph;
        $graph['@graph'][1]['validThrough'] = '2099-02-31';
        source_rejects(
            fn() => (new PublicJobParser())->job(
                '<script type="application/ld+json">' . json_encode($graph) . '</script>',
                $publicUrl,
            ),
        );
        $graph['@graph'][] = $graph['@graph'][1];
        source_rejects(
            fn() => (new PublicJobParser())->job(
                '<script type="application/ld+json">' . json_encode($graph) . '</script>',
                $publicUrl,
            ),
        );
        source_rejects(
            fn() => (new PublicJobParser())->job(
                '<h1>Browser verification required</h1>',
                $publicUrl,
            ),
        );
    },
);
$publicHttp = new class ($publicHtml) extends HttpClient {
    public array $requests = [];
    public array $pages = [];
    public int $pageStatus = 200;
    public string $robots = 'User-agent: *';
    public function __construct(public string $html) {}
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 45,
        int $maxBytes = 4194304,
    ): array {
        $this->requests[] = $url;
        $robots = str_ends_with($url, '/robots.txt');
        $category =
            str_contains($url, '/jobs/software-data') ||
            str_contains($url, '/job/computers-software-development');
        $links = '';
        for ($i = 0; $i < 30; $i++) {
            $links .= '<a href="/listings/fixture-' . $i . '">Fixture</a>';
        }
        return [
            'status' => $robots ? 200 : $this->pageStatus,
            'headers' => [],
            'body' => $robots
                ? $this->robots
                : ($category
                    ? $links
                    : $this->pages[$url] ?? $this->html),
        ];
    }
};
source_check(
    'public collection caps vacancy requests and fetches robots once per run',
    function () use ($publicHttp): void {
        $source = SourceCatalog::get('brightermonday');
        $items = (new PublicJobsAdapter(
            'brightermonday',
            new PublicContent($publicHttp),
            0,
        ))->collect($source, 200);
        source_same(20, count($items));
        source_same(22, count($publicHttp->requests));
        source_same(
            1,
            count(
                array_filter($publicHttp->requests, fn($url) => str_ends_with($url, '/robots.txt')),
            ),
        );
    },
);
source_check(
    'blocked public responses stop collection and report the actual HTTP status',
    function () use ($publicHttp): void {
        $publicHttp->pageStatus = 403;
        $publicHttp->requests = [];
        try {
            (new PublicJobsAdapter('fuzu', new PublicContent($publicHttp), 0))->collect(
                SourceCatalog::get('fuzu'),
                20,
            );
            throw new RuntimeException('Expected a blocked source.');
        } catch (HttpException $exception) {
            source_same(true, str_contains($exception->getMessage(), 'HTTP 403'));
        }
        source_same(2, count($publicHttp->requests));
        $publicHttp->pageStatus = 200;
    },
);
source_check('robots query restrictions are enforced before requesting a page', function () use (
    $publicHttp,
): void {
    $publicHttp->robots = "User-agent: *\nDisallow: /*q=*";
    $publicHttp->requests = [];
    source_rejects(
        fn() => (new PublicContent($publicHttp))->fetch(
            'https://www.brightermonday.co.ke/jobs?q=fixture',
        ),
    );
    source_same(1, count($publicHttp->requests));
    $publicHttp->robots = 'User-agent: *';
});

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
source_check('an incomplete feed entry does not hide later usable vacancies', function () use (
    $xml,
): void {
    preg_match('#<item>(.*?)</item>#s', $xml, $matches);
    $incomplete = str_replace('<company>Fixture organisation</company>', '', $matches[0]);
    $mixed = str_replace('<item>', $incomplete . '<item>', $xml);
    source_same(1, count((new MyJobMagAdapter())->parse($mixed, 200)));
});
source_check('keyword matching respects whole words and dash-separated phrases', function (): void {
    source_same(false, KeywordMatcher::contains('Audit assistant with computer literacy', 'IT'));
    source_same(true, KeywordMatcher::contains('IT support', 'it'));
    source_same(true, KeywordMatcher::contains('Front-end developer', 'front end'));
    source_same(true, KeywordMatcher::contains('Senior PHP/MySQL developer', 'PHP'));
    source_same(true, KeywordMatcher::contains('C++ developer', 'C++'));
    source_same(false, KeywordMatcher::contains('JavaScript developer', 'Java'));
});
source_check(
    'technology focus recognises the requested roles and related technical titles',
    function (): void {
        foreach (
            [
                'IT Officer',
                'ICT Assistant',
                'Software Developer',
                'PHP Developer',
                'MySQL Database Administrator',
                'Frontend Engineer',
                'Front-end Developer',
                'Front End Designer',
                'Systems Administrator',
                'System Admin',
                'Linux Administrator',
                'Backend Developer',
                'Full Stack Developer',
                'DevOps Engineer',
                'Cloud Engineer',
                'Network Technician',
                'Helpdesk Analyst',
                'Information Security Analyst',
                'QA Engineer',
                'Data Engineer',
                'React Native Developer',
            ]
            as $title
        ) {
            source_same(true, TechnologyRoles::matches(['title' => $title]));
        }
    },
);
source_check(
    'nontechnical roles do not qualify through industry or computer literacy',
    function (): void {
        foreach (
            [
                'Audit Assistant',
                'Sales Manager - IT Solutions',
                'HR Officer',
                'Accountant',
                'Customer Support Officer',
                'Security Guard',
                'Office Administrator',
            ]
            as $title
        ) {
            source_same(
                false,
                TechnologyRoles::matches([
                    'title' => $title,
                    'industry' => 'Software / ICT',
                    'description' =>
                        'Work with our information technology team. Computer literacy and MS Office required.',
                ]),
            );
        }
    },
);
source_check('generic technical titles need explicit technical duties', function (): void {
    source_same(
        true,
        TechnologyRoles::matches([
            'title' => 'Junior Developer',
            'description' => 'Build applications with PHP and MySQL.',
        ]),
    );
    source_same(
        true,
        TechnologyRoles::matches([
            'title' => 'Intern',
            'description' => 'Assist with Linux and systems administration.',
        ]),
    );
    source_same(
        false,
        TechnologyRoles::matches([
            'title' => 'Mechanical Engineer',
            'description' => 'Maintain factory production machines.',
        ]),
    );
});
source_check(
    'technology focus composes with optional OR keywords and existing preferences',
    function (): void {
        $settings = SettingsRepository::DEFAULTS['discovery'];
        $settings['role_focus'] = 'technology';
        $job = [
            'title' => 'PHP Developer',
            'description' => 'Build websites.',
            'location' => 'Nairobi',
        ];
        $discovery = new DiscoveryService();
        source_same(true, $discovery->matches($job, $settings));
        $settings['keywords'] = 'MySQL, PHP';
        source_same(true, $discovery->matches($job, $settings));
        $settings['excluded_keywords'] = 'PHP';
        source_same(false, $discovery->matches($job, $settings));
        $settings['excluded_keywords'] = '';
        source_same(
            false,
            $discovery->matches([...$job, 'location' => 'Kampala, Uganda'], $settings),
        );
        source_same(false, $discovery->matches([...$job, 'deadline' => '2000-01-01'], $settings));
        $settings['role_focus'] = 'any';
        $settings['keywords'] = 'IT';
        source_same(false, $discovery->matches([...$job, 'title' => 'Audit Assistant'], $settings));
    },
);
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
    source_check(
        'role focus persists and Discover filters before the result limit',
        function () use ($otherId): void {
            $repository = new SettingsRepository();
            $preferences = $repository->get($otherId, 'discovery');
            (new \App\Services\SettingsService())->save($otherId, 'discovery', [
                ...$preferences,
                'role_focus' => 'technology',
            ]);
            source_same('technology', $repository->get($otherId, 'discovery')['role_focus']);
            $jobs = new JobRepository();
            foreach (['PHP Developer', 'Accountant', 'Sales Manager'] as $title) {
                (new \App\Services\JobService())->create($otherId, [
                    'title' => $title,
                    'employer' => 'Test-only employer',
                    'description' => 'Test-only vacancy.',
                    'location' => 'Nairobi',
                    'application_method' => 'external',
                ]);
            }
            $matched = $jobs->list(
                $otherId,
                limit: 1,
                matches: fn(array $job): bool => (new DiscoveryService())->matches(
                    $job,
                    $repository->get($otherId, 'discovery'),
                ),
            );
            source_same(['PHP Developer'], array_column($matched, 'title'));
            source_same(3, count($jobs->list($otherId)));
            source_same([], $jobs->list(2147483647, matches: fn(array $job): bool => true));
        },
    );
    $service = new SourcePresetService();
    $ids = $service->install($id, ['brightermonday', 'fuzu'], 'email');
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
        'structured discovery finds later matches without exceeding the processing cap',
        function () use ($otherId): void {
            $repository = new SourceRepository();
            $sourceId = $repository->ensurePreset(
                $otherId,
                'myjobmag',
                SourceCatalog::get('myjobmag'),
            );
            $repository->health($otherId, $sourceId, true);
            Database::connection()
                ->prepare(
                    'UPDATE job_sources SET last_checked_at = NULL WHERE id = ? AND user_id = ?',
                )
                ->execute([$sourceId, $otherId]);
            $settings = new SettingsRepository();
            $settings->save($otherId, 'discovery', [
                ...$settings->get($otherId, 'discovery'),
                'jobs_per_run' => 2,
            ]);
            $adapter = new class implements SourceAdapter {
                public function identifier(): string
                {
                    return 'myjobmag';
                }
                public function validate(array $source): void {}
                public function collect(array $source, int $limit): array
                {
                    source_same(200, $limit);
                    $items = [];
                    foreach (
                        [
                            ...array_fill(0, 40, 'Accountant'),
                            'PHP Developer',
                            'System Administrator',
                            'Frontend Developer',
                        ]
                        as $title
                    ) {
                        $items[] = [
                            'structured' => [
                                'title' => $title,
                                'employer' => 'Structured test feed employer',
                                'description' => 'Test-only vacancy.',
                                'location' => 'Nairobi',
                                'application_method' => 'external',
                            ],
                        ];
                    }
                    return $items;
                }
            };
            source_same(2, (new DiscoveryService(['myjobmag' => $adapter]))->run($otherId));
            source_same(2, (new JobRepository())->discoveredToday($otherId));
            $sourceJobs = array_values(
                array_filter(
                    (new JobRepository())->list($otherId),
                    fn(array $job): bool => (int) $job['source_id'] === $sourceId,
                ),
            );
            source_same(
                ['System Administrator', 'PHP Developer'],
                array_column($sourceJobs, 'title'),
            );
        },
    );
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
    $publicUserId = $users->create(
        'Public Collector Fixture',
        'public-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $userIds[] = $publicUserId;
    $publicIds = $service->install($publicUserId, ['brightermonday', 'fuzu']);
    source_check(
        'public presets default to no Gmail and preserve modes and disabled state on reinstall',
        function () use ($service, $publicUserId, $publicIds): void {
            $repository = new SourceRepository();
            source_same(
                'brightermonday_public',
                $repository->get($publicUserId, $publicIds['brightermonday'])['adapter'],
            );
            source_same('untested', $repository->get($publicUserId, $publicIds['fuzu'])['health']);
            source_same(
                false,
                (new SettingsRepository())->get($publicUserId, 'gmail')['monitor_enabled'],
            );
            $service->selectMode($publicUserId, $publicIds['fuzu'], 'email');
            source_same(
                'needs_connection',
                $repository->get($publicUserId, $publicIds['fuzu'])['health'],
            );
            $service->selectMode($publicUserId, $publicIds['fuzu'], 'public');
            $service->refreshEmailHealth($publicUserId);
            source_same('untested', $repository->get($publicUserId, $publicIds['fuzu'])['health']);
            $service->install($publicUserId, ['fuzu']);
            source_same(0, (int) $repository->get($publicUserId, $publicIds['fuzu'])['enabled']);
            source_rejects(fn() => $service->selectMode(2147483647, $publicIds['fuzu'], 'public'));
        },
    );
    source_check(
        'public discovery imports, filters and deduplicates vacancies without Gmail or AI',
        function () use ($publicHttp, $publicUserId, $publicIds): void {
            $repository = new SourceRepository();
            $repository->health($publicUserId, $publicIds['brightermonday'], true);
            $settings = new SettingsRepository();
            $settings->save($publicUserId, 'discovery', [
                ...$settings->get($publicUserId, 'discovery'),
                'role_focus' => 'technology',
            ]);
            $adapter = new PublicJobsAdapter('brightermonday', new PublicContent($publicHttp), 0);
            $service = new DiscoveryService(['brightermonday_public' => $adapter]);
            $reset = fn() => Database::connection()
                ->prepare(
                    'UPDATE job_sources SET last_checked_at = NULL WHERE id = ? AND user_id = ?',
                )
                ->execute([$publicIds['brightermonday'], $publicUserId]);
            $reset();
            source_same(1, $service->run($publicUserId));
            $reset();
            source_same(0, $service->run($publicUserId));
            source_same(1, count((new JobRepository())->list($publicUserId)));
            $settings->save($publicUserId, 'discovery', [
                ...$settings->get($publicUserId, 'discovery'),
                'jobs_per_run' => 1,
            ]);
            $publicHttp->pages['https://www.brightermonday.co.ke/listings/fixture-1'] = str_replace(
                'PHP Developer',
                'Frontend Developer',
                $publicHttp->html,
            );
            $reset();
            source_same(1, $service->run($publicUserId));
            source_same(2, count((new JobRepository())->list($publicUserId)));
            $publicHttp->pages = [];
            $publicHttp->html = str_replace('PHP Developer', 'Accountant', $publicHttp->html);
            $reset();
            source_same(0, $service->run($publicUserId));
            source_same(2, count((new JobRepository())->list($publicUserId)));
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
