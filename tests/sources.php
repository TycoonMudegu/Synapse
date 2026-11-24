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
use App\Services\Discovery\FuzuBrowserClient;
use App\Services\Discovery\GoogleJobsAdapter;
use App\Services\Discovery\TalentJobParser;
use App\Services\Discovery\PublicJobParser;
use App\Services\Discovery\PublicJobsAdapter;
use App\Services\Discovery\SourceCatalog;
use App\Services\Discovery\SourceAdapter;
use App\Services\Discovery\SourcePresetService;
use App\Services\Mail\GmailService;
use App\Services\Security\Crypto;
use App\Services\SettingsService;

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
    'explicit application deadlines take precedence over later listing metadata and advertised email is retained',
    function () use ($publicGraph, $publicUrl): void {
        $graph = $publicGraph;
        $graph['@graph'][1]['description'] =
            '<p>Submit your CV to hiring@example.test on or before 15th November 2026.</p>';
        $html = '<script type="application/ld+json">' . json_encode($graph) . '</script>';
        $job = (new PublicJobParser())->job($html, $publicUrl);
        source_same('2026-11-15', $job['deadline']);
        source_same('email', $job['application_method']);
        source_same('hiring@example.test', $job['application_email']);
    },
);
source_check(
    'company contact emails and ambiguous application recipients are never guessed as a sending destination',
    function () use ($publicGraph, $publicUrl): void {
        foreach (
            [
                'Company enquiries: help@example.test. Apply on our website.',
                'Send your CV to first@example.test or email your CV to second@example.test.',
            ]
            as $description
        ) {
            $graph = $publicGraph;
            $graph['@graph'][1]['description'] = $description;
            $html = '<script type="application/ld+json">' . json_encode($graph) . '</script>';
            $job = (new PublicJobParser())->job($html, $publicUrl);
            source_same('external', $job['application_method']);
            source_same(null, $job['application_email']);
        }
    },
);
source_check(
    'public parser rejects impossible textual dates rather than normalising them into a different month',
    function () use ($publicGraph, $publicUrl): void {
        $graph = $publicGraph;
        $graph['@graph'][1]['description'] =
            'Closing date: 31 February 2026. Apply on our website.';
        $html = '<script type="application/ld+json">' . json_encode($graph) . '</script>';
        source_same('2099-12-31', (new PublicJobParser())->job($html, $publicUrl)['deadline']);
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
    public array $statuses = [];
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
            'status' => $robots ? 200 : $this->statuses[$url] ?? $this->pageStatus,
            'headers' => [],
            'body' => $robots
                ? $this->robots
                : $this->pages[$url] ?? ($category ? $links : $this->html),
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
source_check('public collection continues past isolated vacancy server errors', function () use (
    $publicHttp,
): void {
    $publicHttp->requests = [];
    $publicHttp->statuses = [
        'https://www.brightermonday.co.ke/listings/fixture-0' => 500,
        'https://www.brightermonday.co.ke/listings/fixture-1' => 502,
    ];
    try {
        $items = (new PublicJobsAdapter(
            'brightermonday',
            new PublicContent($publicHttp),
            0,
        ))->collect(SourceCatalog::get('brightermonday'), 2);
        source_same(2, count($items));
        source_same(
            'https://www.brightermonday.co.ke/listings/fixture-2',
            $items[0]['structured']['original_url'],
        );
        source_same(6, count($publicHttp->requests));
    } finally {
        $publicHttp->statuses = [];
    }
});
source_check('public collection stops after three consecutive server errors', function () use (
    $publicHttp,
): void {
    $publicHttp->requests = [];
    $publicHttp->statuses = [
        'https://www.brightermonday.co.ke/listings/fixture-0' => 500,
        'https://www.brightermonday.co.ke/listings/fixture-1' => 503,
        'https://www.brightermonday.co.ke/listings/fixture-2' => 504,
    ];
    try {
        try {
            (new PublicJobsAdapter('brightermonday', new PublicContent($publicHttp), 0))->collect(
                SourceCatalog::get('brightermonday'),
                1,
            );
            throw new RuntimeException('Expected a source server error.');
        } catch (HttpException $exception) {
            source_same(true, str_contains($exception->getMessage(), 'HTTP 504'));
        }
        source_same(5, count($publicHttp->requests));
    } finally {
        $publicHttp->statuses = [];
    }
});
source_check(
    'public collection retains complete vacancies before a server outage',
    function () use ($publicHttp): void {
        $publicHttp->requests = [];
        $publicHttp->statuses = [
            'https://www.brightermonday.co.ke/listings/fixture-1' => 500,
            'https://www.brightermonday.co.ke/listings/fixture-2' => 502,
            'https://www.brightermonday.co.ke/listings/fixture-3' => 503,
        ];
        try {
            $items = (new PublicJobsAdapter(
                'brightermonday',
                new PublicContent($publicHttp),
                0,
            ))->collect(SourceCatalog::get('brightermonday'), 20);
            source_same(1, count($items));
            source_same(6, count($publicHttp->requests));
        } finally {
            $publicHttp->statuses = [];
        }
    },
);
source_check(
    'Fuzu opens job search before its technical category and reuses robots validation',
    function () use ($publicHttp): void {
        $definition = SourceCatalog::get('fuzu');
        $publicHttp->requests = [];
        $publicHttp->pages[$definition['setup_url']] =
            '<a href="/kenya/job/computers-software-development">IT jobs</a>';
        $publicHttp->pages[$definition['url']] = '<a href="/kenya/jobs/fixture-role">Fixture</a>';
        try {
            $items = (new PublicJobsAdapter('fuzu', new PublicContent($publicHttp), 0))->collect(
                $definition,
                1,
            );
            source_same(1, count($items));
            source_same(
                'https://www.fuzu.com/kenya/jobs/fixture-role',
                $items[0]['structured']['original_url'],
            );
            source_same(
                [
                    'https://www.fuzu.com/robots.txt',
                    $definition['setup_url'],
                    $definition['url'],
                    'https://www.fuzu.com/kenya/jobs/fixture-role',
                ],
                $publicHttp->requests,
            );
        } finally {
            $publicHttp->pages = [];
        }
    },
);
source_check(
    'Fuzu browser transport rejects arbitrary destinations before starting Node',
    function (): void {
        $browser = new FuzuBrowserClient();
        foreach (
            [
                'https://www.fuzu.com.attacker.test/kenya/job',
                'https://user@www.fuzu.com/kenya/job',
                'https://www.fuzu.com/login',
                'https://www.fuzu.com/kenya/job?q=developer',
                'https://127.0.0.1/kenya/job',
            ]
            as $url
        ) {
            source_rejects(fn() => $browser->request('GET', $url));
        }
        source_rejects(fn() => $browser->request('POST', SourceCatalog::get('fuzu')['url']));
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

source_check(
    'Corporate Staffing preserves canonical trailing slashes and caps detail requests',
    function () use ($publicHtml): void {
        $definition = SourceCatalog::get('corporatestaffing');
        $http = new class ($publicHtml) extends HttpClient {
            public array $requests = [];
            public function __construct(private string $detail) {}
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
                int $timeout = 45,
                int $maxBytes = 4194304,
            ): array {
                $this->requests[] = $url;
                $links = '';
                for ($i = 0; $i < 30; $i++) {
                    $links .= '<a href="/job/fixture-' . $i . '/?utm_source=fixture">Job</a>';
                }
                $links .=
                    '<a href="https://www.corporatestaffing.co.ke.attacker.test/job/foreign/">Foreign</a><a href="/category/it-jobs-in-kenya/page/2/">Category</a>';
                return [
                    'status' => 200,
                    'headers' => [],
                    'body' => str_ends_with($url, '/robots.txt')
                        ? 'User-agent: *'
                        : (str_contains($url, '/category/')
                            ? $links
                            : $this->detail),
                ];
            }
        };
        $items = (new PublicJobsAdapter('corporatestaffing', new PublicContent($http), 0))->collect(
            $definition,
            200,
        );
        source_same(20, count($items));
        source_same(22, count($http->requests));
        source_same(
            'https://www.corporatestaffing.co.ke/job/fixture-0/',
            $items[0]['structured']['original_url'],
        );
        source_same('https://www.corporatestaffing.co.ke/job/fixture-19/', $http->requests[21]);
        source_rejects(fn() => SourceCatalog::get('corporatestaffing', 'email'));
    },
);
source_check(
    'Google Jobs uses the API source while retaining Google search robots restrictions',
    function (): void {
        source_same(
            false,
            (new PublicContent())->allowed(
                "User-agent: *\nDisallow: /search",
                '/search?q=IT+jobs+Kenya&udm=8',
            ),
        );
        source_same('https://serpapi.com/search.json', SourceCatalog::get('google_jobs')['url']);
        source_same(
            true,
            (new DiscoveryService())->adapter('google_jobs_api') instanceof GoogleJobsAdapter,
        );
        source_rejects(fn() => (new DiscoveryService())->adapter('google_jobs_public'));
    },
);
$googleFixture = [
    'title' => 'PHP Developer',
    'company_name' => 'Google Jobs test fixture',
    'location' => 'Nairobi, Kenya',
    'description' => '<p>Fixture PHP duties.</p><script>unsafe()</script>',
    'apply_options' => [['link' => 'https://employer.example.test/jobs/php?utm_source=google']],
    'detected_extensions' => [
        'schedule_type' => 'Full-time',
        'work_from_home' => true,
        'posted_at' => '2 days ago',
    ],
];
source_check(
    'Google Jobs preserves explicit facts and leaves inferred dates and salary unknown',
    function () use ($googleFixture): void {
        $items = (new GoogleJobsAdapter())->parse([$googleFixture, ['title' => 'Incomplete']]);
        source_same(1, count($items));
        $job = $items[0]['structured'];
        source_same('PHP Developer', $job['title']);
        source_same('Google Jobs test fixture', $job['employer']);
        source_same('Fixture PHP duties.', $job['description']);
        source_same('Nairobi, Kenya', $job['location']);
        source_same('full_time', $job['employment_type']);
        source_same('remote', $job['workplace']);
        source_same(null, $job['deadline']);
        source_same(false, isset($job['salary_min']));
        source_same('https://employer.example.test/jobs/php', $job['original_url']);
    },
);
source_check(
    'Google Jobs excludes unsafe links and generic search links without fetching application sites',
    function () use ($googleFixture): void {
        foreach (
            [
                'javascript:alert(1)',
                'https://user@public.example.test/jobs/php',
                'https://127.0.0.1/jobs/php',
                'https://127.1/jobs/php',
                'https://[::ffff:127.0.0.1]/jobs/php',
                'https://www.google.com/search?q=IT+jobs',
                'https://employer.example.test:8443/jobs/php',
            ]
            as $unsafe
        ) {
            $job = $googleFixture;
            $job['apply_options'] = [['link' => $unsafe]];
            source_same([], (new GoogleJobsAdapter())->parse([$job]));
        }
        $job = $googleFixture;
        $job['source_link'] = 'https://127.0.0.1/private';
        $job['apply_options'] = [['link' => 'https://www.linkedin.com/jobs/view/fixture']];
        $http = new class extends HttpClient {
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null,
                int $timeout = 45,
                int $maxBytes = 4194304,
            ): array {
                throw new RuntimeException(
                    'Parsing Google results must never fetch an application site.',
                );
            }
        };
        source_same(
            'https://www.linkedin.com/jobs/view/fixture',
            (new GoogleJobsAdapter($http))->parse([$job])[0]['structured']['original_url'],
        );
        $job['apply_options'] = [];
        $job['share_link'] = 'https://www.google.com/search?q=IT+jobs&htidocid=fixture-job-id';
        source_same(
            'https://www.google.com/search?htidocid=fixture-job-id&q=IT+jobs',
            (new GoogleJobsAdapter())->parse([$job])[0]['structured']['original_url'],
        );
    },
);

$talentFixture =
    '<link rel="canonical" href="https://ke.talent.com/view?id=1">' .
    '<main><article><header><div data-hook="job-card-container"><h1>PHP Developer</h1>' .
    '<div class="styles_companyLocationRow__fixture"><span>Talent test employer</span><span>&nbsp;•&nbsp;</span><span>Nairobi, KE</span></div>' .
    '<span>30+ days ago</span><a href="/redirect?id=1">Quick Apply</a></div></header>' .
    '<div class="styles_jobInfoContent__fixture"><p>Fixture PHP duties.</p><script>unsafe()</script></div></article>' .
    '<aside><article><h2>Unrelated vacancy</h2><span>Unrelated employer</span><div class="styles_jobInfoContent__fixture">Unrelated description</div></article></aside></main>';
source_check(
    'Talent extracts only the primary advertised vacancy and leaves absent facts unknown',
    function () use ($talentFixture): void {
        $job = (new TalentJobParser())->job($talentFixture, 'https://ke.talent.com/view?id=1');
        source_same('PHP Developer', $job['title']);
        source_same('Talent test employer', $job['employer']);
        source_same('Fixture PHP duties.', $job['description']);
        source_same('Nairobi, KE', $job['location']);
        source_same('not_specified', $job['employment_type']);
        source_same('not_specified', $job['workplace']);
        source_same(null, $job['deadline']);
        source_same(false, isset($job['salary_min']));
        source_same('https://ke.talent.com/view?id=1', $job['original_url']);
    },
);
source_check('Talent rejects incomplete, ambiguous and mismatched vacancy pages', function () use (
    $talentFixture,
): void {
    $parser = new TalentJobParser();
    foreach (
        [
            str_replace('Talent test employer', '', $talentFixture),
            str_replace('Fixture PHP duties.', '', $talentFixture),
            str_replace('<h1>PHP Developer</h1>', '', $talentFixture),
            str_replace(
                '</main>',
                '<article><h1>Another primary vacancy</h1></article></main>',
                $talentFixture,
            ),
            str_repeat('x', 4194305),
        ]
        as $html
    ) {
        source_rejects(fn() => $parser->job($html, 'https://ke.talent.com/view?id=1'));
    }
    source_rejects(fn() => $parser->job($talentFixture, 'https://ke.talent.com/view?id=2'));
});
source_check(
    'Talent links retain only numeric Kenya vacancy IDs and exclude redirect, API and foreign URLs',
    function (): void {
        $html =
            '<a href="/view?id=123&utm_source=fixture">Job</a><a href="https://ke.talent.com/view?id=123">Duplicate</a>' .
            '<a href="/redirect?id=124">Redirect</a><a href="/api/jobs?id=124">API</a><a href="/view?id[]=124">Array</a>' .
            '<a href="/view?id=124&url=https://127.0.0.1">Forward</a><a href="https://www.talent.com/view?id=124">US</a>' .
            '<a href="https://ke.talent.com.attacker.test/view?id=124">Spoof</a><a href="https://user@ke.talent.com/view?id=124">Credentials</a>' .
            '<a href="https://ke.talent.com:8443/view?id=124">Port</a><a href="/view?id=not-a-number">Non-numeric</a>';
        source_same(['https://ke.talent.com/view?id=123'], (new TalentJobParser())->links($html));
        source_same(
            true,
            (new PublicContent())->allowed(
                "User-agent: *\nDisallow: /redirect*\nDisallow: /api/",
                '/view?id=123',
            ),
        );
        source_same(
            false,
            (new PublicContent())->allowed(
                "User-agent: *\nDisallow: /redirect*\nDisallow: /api/",
                '/redirect?id=123',
            ),
        );
    },
);
source_check('Talent collection reuses robots and respects the 20-detail cap', function () use (
    $talentFixture,
): void {
    $http = new class ($talentFixture) extends HttpClient {
        public array $requests = [];
        public function __construct(private string $detail) {}
        public function request(
            string $method,
            string $url,
            array $headers = [],
            ?string $body = null,
            int $timeout = 45,
            int $maxBytes = 4194304,
        ): array {
            $this->requests[] = $url;
            $links = '';
            for ($i = 1; $i <= 30; $i++) {
                $links .= '<a href="/view?id=' . $i . '">Fixture</a>';
            }
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            return [
                'status' => 200,
                'headers' => [],
                'body' => str_ends_with($url, '/robots.txt')
                    ? "User-agent: *\nDisallow: /redirect*\nDisallow: /api/"
                    : (str_contains($url, '/jobs/')
                        ? $links
                        : str_replace(
                            'view?id=1"',
                            'view?id=' . ($query['id'] ?? '') . '"',
                            $this->detail,
                        )),
            ];
        }
    };
    source_same(
        20,
        count(
            (new PublicJobsAdapter('talent', new PublicContent($http), 0))->collect(
                SourceCatalog::get('talent'),
                200,
            ),
        ),
    );
    source_same(22, count($http->requests));
    source_same('https://ke.talent.com/view?id=20', $http->requests[21]);
});
source_check(
    'Indeed imports only explicit Indeed vacancy links rather than provider labels',
    function () use ($googleFixture): void {
        $parser = new GoogleJobsAdapter(sourceKey: 'indeed');
        source_same('indeed_api', $parser->identifier());
        $job = $googleFixture;
        $job['via'] = 'Indeed';
        source_same([], $parser->parse([$job]));
        foreach (
            [
                'https://www.indeed.com.attacker.test/viewjob?jk=0123456789abcdef',
                'https://www.indeed.com/jobs?q=PHP',
                'https://www.indeed.com/viewjob?jk[]=0123456789abcdef',
                'https://www.indeed.com/viewjob?jk=not-a-job-id',
                'https://user@www.indeed.com/viewjob?jk=0123456789abcdef',
            ]
            as $url
        ) {
            $job['source_link'] = $url;
            source_same([], $parser->parse([$job]));
        }
        $job['source_link'] = 'https://employer.example.test/jobs/php';
        $job['apply_options'][] = [
            'link' => 'https://za.indeed.com/viewjob?jk=0123456789ABCDEF&from=google',
        ];
        source_same(
            'https://za.indeed.com/viewjob?jk=0123456789abcdef',
            $parser->parse([$job])[0]['structured']['original_url'],
        );
    },
);

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
    $apiUserId = $users->create(
        'Google API Fixture',
        'api-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $userIds[] = $apiUserId;
    $apiIds = $service->install($apiUserId);
    $apiSource = (new SourceRepository())->get($apiUserId, $apiIds['google_jobs']);
    $apiHttp = new class extends HttpClient {
        public array $requests = [];
        public array $responses = [];
        public function request(
            string $method,
            string $url,
            array $headers = [],
            ?string $body = null,
            int $timeout = 45,
            int $maxBytes = 4194304,
        ): array {
            $this->requests[] = $url;
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode(array_shift($this->responses) ?? ['jobs_results' => []]),
            ];
        }
    };
    source_check(
        'all seven presets install idempotently with public/API modes and no Gmail requirement',
        function () use ($service, $apiUserId, $apiIds): void {
            source_same(7, count($apiIds));
            source_same($apiIds, $service->install($apiUserId));
            $sources = new SourceRepository();
            source_same(
                'corporatestaffing_public',
                $sources->get($apiUserId, $apiIds['corporatestaffing'])['adapter'],
            );
            source_same(
                'google_jobs_api',
                $sources->get($apiUserId, $apiIds['google_jobs'])['adapter'],
            );
            source_same('untested', $sources->get($apiUserId, $apiIds['google_jobs'])['health']);
            source_same('talent_public', $sources->get($apiUserId, $apiIds['talent'])['adapter']);
            source_same('indeed_api', $sources->get($apiUserId, $apiIds['indeed'])['adapter']);
            source_same([], $sources->enabled($apiUserId, 20));
            source_same(
                false,
                (new SettingsRepository())->get($apiUserId, 'gmail')['monitor_enabled'],
            );
            source_rejects(
                fn() => $service->selectMode($apiUserId, $apiIds['corporatestaffing'], 'email'),
            );
        },
    );
    source_check(
        'Google Jobs without a key stays inactive without making an API request',
        function () use ($apiHttp, $apiSource): void {
            source_rejects(fn() => (new GoogleJobsAdapter($apiHttp))->validate($apiSource));
            source_same([], $apiHttp->requests);
        },
    );
    source_check(
        'Google Jobs credentials are encrypted, preserved when blank and isolated per account',
        function () use ($apiUserId, $otherId): void {
            (new SettingsService())->save($apiUserId, 'google_jobs', [
                'api_key' => 'fixture-private-key',
                'query' => 'PHP developer jobs',
                'location' => 'Nairobi, Kenya',
            ]);
            $settings = (new SettingsRepository())->get($apiUserId, 'google_jobs');
            source_same(false, str_contains(json_encode($settings), 'fixture-private-key'));
            source_same('fixture-private-key', Crypto::decrypt($settings['encrypted_key']));
            (new SettingsService())->save($apiUserId, 'google_jobs', ['api_key' => '']);
            source_same($settings, (new SettingsRepository())->get($apiUserId, 'google_jobs'));
            source_same(
                '',
                (new SettingsRepository())->get($otherId, 'google_jobs')['encrypted_key'],
            );
            source_rejects(
                fn() => (new SettingsService())->save($apiUserId, 'google_jobs', ['query' => '']),
            );
        },
    );
    source_check(
        'Google Jobs caps pagination at two fixed-host API requests and deduplicates result URLs',
        function () use ($apiHttp, $apiSource, $googleFixture): void {
            $second = $googleFixture;
            $second['title'] = 'Frontend Developer';
            $second['apply_options'] = [['link' => 'https://employer.example.test/jobs/frontend']];
            $apiHttp->responses = [
                [
                    'jobs_results' => [$googleFixture],
                    'serpapi_pagination' => [
                        'next_page_token' => 'fixture-token',
                        'next' => 'https://attacker.example.test/steal-key',
                    ],
                ],
                [
                    'jobs_results' => [$googleFixture, $second],
                    'serpapi_pagination' => ['next_page_token' => 'another-token'],
                ],
            ];
            $items = (new GoogleJobsAdapter($apiHttp))->collect($apiSource, 200);
            source_same(2, count($items));
            source_same(2, count($apiHttp->requests));
            foreach ($apiHttp->requests as $url) {
                source_same('serpapi.com', parse_url($url, PHP_URL_HOST));
            }
            parse_str(parse_url($apiHttp->requests[1], PHP_URL_QUERY), $parameters);
            source_same('fixture-token', $parameters['next_page_token']);
            source_same('PHP developer jobs', $parameters['q']);
            source_same('Nairobi, Kenya', $parameters['location']);
        },
    );
    source_check(
        'Google Jobs rejects malformed results and hides provider errors that echo secrets',
        function () use ($apiHttp, $apiSource): void {
            $adapter = new GoogleJobsAdapter($apiHttp);
            $apiHttp->responses = [['error' => 'Bad fixture-private-key', 'jobs_results' => []]];
            try {
                $adapter->collect($apiSource, 20);
                throw new RuntimeException('Expected provider failure.');
            } catch (HttpException $error) {
                source_same(false, str_contains($error->getMessage(), 'fixture-private-key'));
            }
            $apiHttp->responses = [['jobs_results' => 'malformed']];
            source_rejects(fn() => $adapter->collect($apiSource, 20));
            $apiHttp->responses = [['jobs_results' => []]];
            source_rejects(fn() => $adapter->validate($apiSource));
        },
    );
    source_check(
        'Google Jobs discovery applies saved filters and existing URL deduplication',
        function () use ($apiUserId, $apiIds, $apiHttp, $googleFixture): void {
            $settings = new SettingsRepository();
            $settings->save($apiUserId, 'discovery', [
                ...$settings->get($apiUserId, 'discovery'),
                'role_focus' => 'technology',
            ]);
            (new \App\Services\JobService())->create(
                $apiUserId,
                (new GoogleJobsAdapter())->parse([$googleFixture])[0]['structured'],
            );
            $second = $googleFixture;
            $second['title'] = 'Frontend Developer';
            $second['apply_options'] = [['link' => 'https://employer.example.test/jobs/frontend']];
            $unrelated = $googleFixture;
            $unrelated['title'] = 'Accountant';
            $unrelated['description'] = 'Fixture accounting and financial reporting duties.';
            $unrelated['apply_options'] = [
                ['link' => 'https://employer.example.test/jobs/accountant'],
            ];
            $repository = new SourceRepository();
            $repository->health($apiUserId, $apiIds['google_jobs'], true);
            $reset = fn() => Database::connection()
                ->prepare('UPDATE job_sources SET last_checked_at = NULL WHERE user_id = ?')
                ->execute([$apiUserId]);
            $reset();
            $apiHttp->responses = [['jobs_results' => [$googleFixture, $second, $unrelated]]];
            $service = new DiscoveryService(['google_jobs_api' => new GoogleJobsAdapter($apiHttp)]);
            source_same(1, $service->run($apiUserId));
            source_same(2, count((new JobRepository())->list($apiUserId)));
            $reset();
            $apiHttp->responses = [['jobs_results' => [$googleFixture, $second, $unrelated]]];
            source_same(0, $service->run($apiUserId));
        },
    );
    source_check(
        'Indeed reuses the encrypted Google connection and validation spends at most one request',
        function () use ($apiUserId, $apiIds, $apiHttp, $googleFixture): void {
            $source = (new SourceRepository())->get($apiUserId, $apiIds['indeed']);
            $adapter = new GoogleJobsAdapter($apiHttp, 'indeed');
            $apiHttp->requests = [];
            $apiHttp->responses = [
                [
                    'jobs_results' => [$googleFixture],
                    'serpapi_pagination' => ['next_page_token' => 'fixture-next'],
                ],
            ];
            source_rejects(fn() => $adapter->validate($source));
            source_same(1, count($apiHttp->requests));
            parse_str(parse_url($apiHttp->requests[0], PHP_URL_QUERY), $parameters);
            source_same('fixture-private-key', $parameters['api_key']);
            source_same('google_jobs', $parameters['engine']);
            $indeed = $googleFixture;
            $indeed['apply_options'] = [
                ['link' => 'https://www.indeed.com/viewjob?jk=0123456789abcdef'],
            ];
            $apiHttp->responses = [['jobs_results' => [$googleFixture, $indeed]]];
            source_same(
                'https://www.indeed.com/viewjob?jk=0123456789abcdef',
                $adapter->collect($source, 20)[0]['structured']['original_url'],
            );
        },
    );
    source_check(
        'Indeed discovery filters before saving and deduplicates Google Jobs vacancies',
        function () use ($apiUserId, $apiIds, $apiHttp, $googleFixture): void {
            $repository = new SourceRepository();
            $repository->toggle($apiUserId, $apiIds['google_jobs'], false);
            $repository->health($apiUserId, $apiIds['indeed'], true);
            Database::connection()
                ->prepare('UPDATE job_sources SET last_checked_at = NULL WHERE user_id = ?')
                ->execute([$apiUserId]);
            $duplicate = $googleFixture;
            $duplicate['apply_options'] = [
                ['link' => 'https://www.indeed.com/viewjob?jk=0123456789abcdef'],
            ];
            $fresh = $duplicate;
            $fresh['title'] = 'Backend Engineer';
            $fresh['apply_options'] = [
                ['link' => 'https://www.indeed.com/viewjob?jk=fedcba9876543210'],
            ];
            $apiHttp->responses = [['jobs_results' => [$googleFixture, $duplicate, $fresh]]];
            source_same(
                1,
                (new DiscoveryService([
                    'indeed_api' => new GoogleJobsAdapter($apiHttp, 'indeed'),
                ]))->run($apiUserId),
            );
            source_same(3, count((new JobRepository())->list($apiUserId)));
        },
    );
    source_check(
        'changing or removing Google Jobs credentials disables only that account source',
        function () use ($apiUserId, $apiIds, $otherId): void {
            $repository = new SourceRepository();
            $otherIds = (new SourcePresetService())->install($otherId, ['google_jobs', 'indeed']);
            $repository->health($otherId, $otherIds['google_jobs'], true);
            $repository->health($otherId, $otherIds['indeed'], true);
            $repository->health($apiUserId, $apiIds['google_jobs'], true);
            (new SettingsService())->save($apiUserId, 'google_jobs', [
                'query' => 'Cloud engineer jobs',
            ]);
            source_same(0, (int) $repository->get($apiUserId, $apiIds['google_jobs'])['enabled']);
            source_same('untested', $repository->get($apiUserId, $apiIds['google_jobs'])['health']);
            source_same(0, (int) $repository->get($apiUserId, $apiIds['indeed'])['enabled']);
            source_same(1, (int) $repository->get($otherId, $otherIds['google_jobs'])['enabled']);
            source_same(1, (int) $repository->get($otherId, $otherIds['indeed'])['enabled']);
            (new SettingsService())->save($apiUserId, 'google_jobs', ['remove_key' => '1']);
            source_same(
                '',
                (new SettingsRepository())->get($apiUserId, 'google_jobs')['encrypted_key'],
            );
            source_rejects(
                fn() => (new GoogleJobsAdapter())->validate(
                    $repository->get($apiUserId, $apiIds['google_jobs']),
                ),
            );
            source_rejects(
                fn() => (new GoogleJobsAdapter(sourceKey: 'indeed'))->validate(
                    $repository->get($apiUserId, $apiIds['indeed']),
                ),
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
