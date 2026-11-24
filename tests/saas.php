<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\AccountRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\Billing\MpesaService;
use App\Services\Documents\CvReviewService;
use App\Services\Entitlements;
use App\Services\OnboardingService;
use App\Services\Security\Crypto;

require BASE_PATH . '/scripts/migrate.php';
$passed = 0;
$ids = [];
$promoIds = [];
function saas_check(string $name, callable $operation): void
{
    global $passed;
    $operation();
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}
function eq(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}
function denied(callable $operation, int $status = 422): void
{
    try {
        $operation();
    } catch (HttpException $e) {
        eq($status, $e->status);
        return;
    }
    throw new RuntimeException('Expected operation to be rejected.');
}
function member(int $id, string $plan, ?string $expires = null): void
{
    Database::connection()
        ->prepare(
            'INSERT INTO memberships (user_id, plan, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE plan=VALUES(plan), expires_at=VALUES(expires_at)',
        )
        ->execute([$id, $plan, $expires]);
}
function document(int $id, string $text, string $kind = 'master_cv'): int
{
    return (new DocumentRepository())->create($id, [
        'name' => 'Fixture CV.docx',
        'kind' => $kind,
        'storage_path' => bin2hex(random_bytes(24)) . '.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'byte_size' => 200,
        'extracted_text' => $text,
    ]);
}
final class FakeDaraja extends HttpClient
{
    public array $calls = [];
    public array $result = [];
    public bool $fail = false;
    public function json(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = 45,
    ): array {
        $this->calls[] = [$method, $url, $data];
        if (str_contains($url, '/oauth/')) {
            return ['access_token' => 'test-only'];
        }
        if (str_contains($url, '/processrequest')) {
            return [
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'test-checkout-' . count($this->calls),
                'MerchantRequestID' => 'test-merchant',
            ];
        }
        if ($this->fail) {
            throw new HttpException('Fixture timeout', 502);
        }
        return $this->result;
    }
}
try {
    $users = new UserRepository();
    $user = $users->create(
        'SaaS Fixture',
        'saas-' . bin2hex(random_bytes(7)) . '@example.test',
        'fixture-password-12345',
    );
    $other = $users->create(
        'SaaS Other',
        'saas-' . bin2hex(random_bytes(7)) . '@example.test',
        'fixture-password-12345',
    );
    $ids = [$user, $other];
    $accounts = new AccountRepository();
    $accounts->saveSite('job_sources', [
        'myjobmag' => ['enabled' => true, 'health' => 'connected'],
        'talent' => ['enabled' => true, 'health' => 'connected'],
        'fuzu' => ['enabled' => false, 'health' => 'error'],
        'indeed' => ['enabled' => false, 'health' => 'untested'],
    ]);
    saas_check('new accounts start free with incomplete onboarding', function () use (
        $users,
        $user,
        $accounts,
    ): void {
        eq(null, $users->byId($user)['onboarded_at']);
        eq(1, (int) $users->byId($user)['onboarding_step']);
        eq(false, $accounts->membership($user)['active']);
    });
    saas_check(
        'catalogue excludes unconfigured sites and respects career fields',
        function (): void {
            eq(
                ['myjobmag', 'talent'],
                array_keys((new OnboardingService())->sources('technology')),
            );
            eq(['myjobmag'], array_keys((new OnboardingService())->sources('finance')));
        },
    );
    saas_check('preferences reject unavailable and wrong-field sources', function () use (
        $user,
    ): void {
        denied(
            fn() => (new OnboardingService())->preferences($user, [
                'field_key' => 'finance',
                'sources' => ['talent'],
            ]),
        );
        denied(
            fn() => (new OnboardingService())->preferences($user, [
                'field_key' => 'technology',
                'sources' => ['indeed'],
            ]),
        );
        denied(fn() => (new OnboardingService())->preferences($user, ['field_key' => 'other']));
    });
    saas_check('search preferences and selected sites stay scoped to their owner', function () use (
        $user,
        $other,
        $accounts,
    ): void {
        (new OnboardingService())->preferences($user, [
            'field_key' => 'technology',
            'role_keywords' => 'PHP developer',
            'location' => 'Kenya',
            'sources' => ['myjobmag'],
        ]);
        eq('PHP developer', $accounts->preferences($user)['role_keywords']);
        eq('', $accounts->preferences($other)['role_keywords']);
        eq(1, count((new \App\Repositories\SourceRepository())->enabled($user, 5)));
        eq([], (new \App\Repositories\SourceRepository())->all($other));
    });
    saas_check('free accounts cannot enable automation or use hosted AI', function () use (
        $user,
    ): void {
        denied(fn() => (new Entitlements())->requirePaid($user), 402);
        denied(fn() => (new Entitlements())->requireAi($user), 402);
        denied(
            fn() => (new \App\Services\SettingsService())->save($user, 'automation', [
                'mode' => 'automatic',
                'confirm_automatic' => 1,
            ]),
            402,
        );
        denied(
            fn() => (new \App\Services\SettingsService())->save($user, 'automation', [
                'discovery_enabled' => 1,
            ]),
            402,
        );
    });
    $settings = new SettingsRepository();
    $provider = $settings->saveProvider($user, 0, [
        'Fixture BYOK',
        'https://generativelanguage.googleapis.com/v1beta/openai',
        Crypto::encrypt('fixture-key'),
        'fixture-model',
        json_encode(['max_tokens' => 100, 'input_per_million' => 1, 'output_per_million' => 1]),
    ]);
    $settings->save($user, 'ai', ['screening_provider' => $provider]);
    saas_check('a personal provider key enables AI on a free account', function () use (
        $user,
    ): void {
        (new Entitlements())->requireAi($user);
    });
    saas_check('provider ownership prevents cross-account key use', function () use (
        $other,
        $provider,
    ): void {
        denied(fn() => (new Entitlements())->requireAi($other, $provider), 402);
    });
    $managed = $settings->saveProvider($other, 0, [
        'Managed',
        'https://generativelanguage.googleapis.com/v1beta/openai',
        Crypto::encrypt('fixture-managed'),
        'fixture-model',
        json_encode(['managed' => true]),
    ]);
    saas_check('a managed key cannot be converted to BYOK without a new secret', function () use (
        $other,
        $managed,
    ): void {
        denied(
            fn() => (new \App\Services\SettingsService())->provider($other, [
                'id' => $managed,
                'name' => 'Changed',
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'model' => 'fixture-model',
            ]),
        );
    });
    saas_check('expired subscriptions immediately lose paid access', function () use (
        $accounts,
        $user,
    ): void {
        member($user, 'monthly', date('Y-m-d H:i:s', time() - 100));
        eq(false, $accounts->membership($user)['active']);
        member($user, 'monthly', date('Y-m-d H:i:s', time() + 86400));
        eq(true, $accounts->membership($user)['active']);
        member($user, 'free');
    });
    $text =
        "Jane Applicant jane@example.test +254 712 345 678\nProfessional summary\nPHP software developer building database applications for clients. Skills PHP programming software database cloud network. Experience Built 12 applications and improved delivery by 20%. Education Bachelor of Science. Projects Developed and maintained secure systems, worked with teams and supported internal customers with technical support and clear documentation.";
    $cv = document($user, $text);
    saas_check('CV grades reflect readable evidence and selected career field', function () use (
        $text,
    ): void {
        $service = new CvReviewService();
        eq(100, $service->assess($text, 'technology')['score']);
        eq(80, $service->assess($text, 'healthcare')['score']);
        eq(null, $service->assess('image only', 'technology')['score']);
    });
    saas_check(
        'the first grade is free and cached without consuming a second check',
        function () use ($user, $cv, $accounts): void {
            $first = (new CvReviewService())->grade($user, $cv);
            eq(100, $first['score']);
            eq($first, (new CvReviewService())->grade($user, $cv));
            eq(1, (int) $accounts->preferences($user)['initial_grade_used']);
        },
    );
    saas_check('ongoing grades and AI CV improvements require Plus', function () use (
        $user,
        $text,
    ): void {
        $second = document($user, $text);
        denied(fn() => (new CvReviewService())->grade($user, $second), 402);
        denied(fn() => (new CvReviewService())->advice($user), 402);
    });
    saas_check('another user cannot grade someone else’s CV', function () use ($other, $cv): void {
        denied(fn() => (new CvReviewService())->grade($other, $cv), 404);
    });
    saas_check('cover letters are not accepted as CVs', function () use ($other, $text): void {
        $id = document($other, $text, 'cover_letter_template');
        denied(fn() => (new CvReviewService())->grade($other, $id));
    });
    saas_check(
        'M-Pesa phone numbers are normalised and invalid numbers rejected',
        function (): void {
            eq('254712345678', MpesaService::phone('0712 345 678'));
            eq('254112345678', MpesaService::phone('+254 112 345 678'));
            denied(fn() => MpesaService::phone('123'));
        },
    );
    foreach (
        [
            'MPESA_CONSUMER_KEY' => 'fixture',
            'MPESA_CONSUMER_SECRET' => 'fixture',
            'MPESA_SHORTCODE' => '174379',
            'MPESA_PASSKEY' => 'fixture',
            'MPESA_CALLBACK_URL' => 'https://example.com/api/billing/mpesa/callback',
            'MPESA_ENV' => 'sandbox',
            'APP_ENV' => 'test',
        ]
        as $key => $value
    ) {
        putenv($key . '=' . $value);
    }
    $http = new FakeDaraja();
    $mpesa = new MpesaService($http);
    $payment = $mpesa->start($user, 'monthly', '0712345678');
    saas_check('checkout uses server-owned prices and a normalised phone', function () use (
        $http,
    ): void {
        eq(200, $http->calls[1][2]['Amount']);
        eq('254712345678', $http->calls[1][2]['PhoneNumber']);
    });
    saas_check('concurrent checkout requests are rejected', function () use ($mpesa, $user): void {
        denied(fn() => $mpesa->start($user, 'monthly', '0712345678'), 429);
    });
    saas_check('payment records are private to their owner', function () use (
        $other,
        $payment,
    ): void {
        denied(fn() => (new PaymentRepository())->get($other, $payment), 404);
    });
    saas_check('a forged callback cannot unlock a subscription', function () use (
        $mpesa,
        $user,
        $payment,
        $accounts,
    ): void {
        $row = (new PaymentRepository())->get($user, $payment);
        $mpesa->callback(
            [
                'Body' => [
                    'stkCallback' => [
                        'CheckoutRequestID' => $row['checkout_id'],
                        'ResultCode' => 0,
                    ],
                ],
            ],
            str_repeat('a', 64),
        );
        eq(false, $accounts->membership($user)['active']);
    });
    saas_check('query responses for a different checkout cannot unlock access', function () use (
        $http,
        $mpesa,
        $user,
        $payment,
        $accounts,
    ): void {
        $http->result = [
            'ResponseCode' => '0',
            'ResultCode' => 0,
            'CheckoutRequestID' => 'different',
            'MerchantRequestID' => 'test-merchant',
        ];
        $mpesa->check((new PaymentRepository())->get($user, $payment));
        eq(false, $accounts->membership($user)['active']);
    });
    saas_check('verified payment grants exactly one month and is idempotent', function () use (
        $http,
        $mpesa,
        $user,
        $payment,
        $accounts,
    ): void {
        Database::connection()
            ->prepare('UPDATE payments SET last_checked_at = NULL WHERE id = ?')
            ->execute([$payment]);
        $row = (new PaymentRepository())->get($user, $payment);
        $http->result = [
            'ResponseCode' => '0',
            'ResultCode' => 0,
            'CheckoutRequestID' => $row['checkout_id'],
            'MerchantRequestID' => $row['merchant_id'],
        ];
        eq('paid', $mpesa->check($row)['status']);
        $first = $accounts->membership($user);
        eq(true, $first['active']);
        eq('monthly', $first['plan']);
        (new PaymentRepository())->settle($payment, '0');
        eq($first, $accounts->membership($user));
    });
    saas_check('verified lifetime payment grants lifetime access', function () use (
        $other,
        $accounts,
    ): void {
        $payments = new PaymentRepository();
        $id = $payments->create(
            $other,
            'lifetime',
            1500,
            '254712345678',
            'sandbox',
            bin2hex(random_bytes(32)),
        );
        $payments->started($id, 'lifetime-checkout', 'lifetime-merchant');
        $payments->settle($id, '0');
        eq('lifetime', $accounts->membership($other)['plan']);
    });
    saas_check('a cancelled payment does not grant access', function () use (
        $user,
        $accounts,
    ): void {
        member($user, 'free');
        $payments = new PaymentRepository();
        $id = $payments->create(
            $user,
            'monthly',
            200,
            '254712345678',
            'sandbox',
            bin2hex(random_bytes(32)),
        );
        $payments->started($id, 'cancelled-checkout', 'cancelled-merchant');
        $payments->settle($id, '1032');
        eq(false, $accounts->membership($user)['active']);
    });
    saas_check('sandbox payments cannot grant production access', function () use (
        $user,
        $accounts,
    ): void {
        $payments = new PaymentRepository();
        $id = $payments->create(
            $user,
            'monthly',
            200,
            '254712345678',
            'sandbox',
            bin2hex(random_bytes(32)),
        );
        $payments->started($id, 'sandbox-checkout', 'sandbox-merchant');
        putenv('APP_ENV=production');
        denied(fn() => $payments->settle($id, '0'));
        eq(false, $accounts->membership($user)['active']);
        putenv('APP_ENV=test');
    });
    saas_check('users cannot raise the owner-controlled managed AI budget', function () use (
        $other,
        $managed,
        $settings,
    ): void {
        $settings->saveProvider($other, $managed, [
            'Managed',
            'https://generativelanguage.googleapis.com/v1beta/openai',
            Crypto::encrypt('fixture-managed'),
            'fixture-model',
            json_encode([
                'managed' => true,
                'max_tokens' => 100,
                'input_per_million' => 100,
                'output_per_million' => 100,
            ]),
        ]);
        $settings->save($other, 'ai', [
            'screening_provider' => $managed,
            'monthly_limit_usd' => 10000,
        ]);
        putenv('SITE_AI_MONTHLY_BUDGET_USD=0.01');
        $provider = new class implements \App\Services\AI\Provider {
            public function generate(array $configuration, array $messages, array $schema): array
            {
                throw new RuntimeException(
                    'Provider must not be called when the owner budget is exceeded.',
                );
            }
        };
        denied(
            fn() => (new \App\Services\AI\AiService($provider))->run($other, 'test', [
                'request' => 'fixture',
            ]),
        );
    });
    $googleHttp = new class extends HttpClient {
        public array $identity = [];
        public function request(
            string $method,
            string $url,
            array $headers = [],
            ?string $body = null,
            int $timeout = 45,
            int $maxBytes = 4194304,
        ): array {
            return ['status' => 200, 'body' => '{"access_token":"fixture-token"}', 'headers' => []];
        }
        public function json(
            string $method,
            string $url,
            array $data = [],
            array $headers = [],
            int $timeout = 45,
        ): array {
            return $this->identity;
        }
    };
    foreach (
        [
            'GOOGLE_CLIENT_ID' => 'fixture-client',
            'GOOGLE_CLIENT_SECRET' => 'fixture-secret',
            'GOOGLE_SIGNIN_REDIRECT_URI' => 'https://example.com/auth/google/callback',
        ]
        as $key => $value
    ) {
        putenv($key . '=' . $value);
    }
    $google = new \App\Services\Security\GoogleSignIn($googleHttp);
    saas_check(
        'Google sign-in uses state and PKCE without requesting Gmail access',
        function () use ($google): void {
            parse_str(parse_url($google->authorizationUrl(), PHP_URL_QUERY), $query);
            eq('openid email profile', $query['scope']);
            eq('S256', $query['code_challenge_method']);
            eq($_SESSION['google_signin']['state'], $query['state']);
            eq(
                rtrim(
                    strtr(
                        base64_encode(hash('sha256', $_SESSION['google_signin']['verifier'], true)),
                        '+/',
                        '-_',
                    ),
                    '=',
                ),
                $query['code_challenge'],
            );
            denied(fn() => $google->callback(['state' => 'incorrect-state', 'code' => 'fixture']));
        },
    );
    saas_check('Google rejects unverified identities', function () use (
        $google,
        $googleHttp,
    ): void {
        $google->authorizationUrl();
        $googleHttp->identity = [
            'sub' => 'fixture-unverified',
            'email' => 'unverified@example.test',
            'email_verified' => false,
        ];
        denied(
            fn() => $google->callback([
                'state' => $_SESSION['google_signin']['state'],
                'code' => 'fixture',
            ]),
        );
    });
    saas_check(
        'Google cannot silently take over an existing password account by email',
        function () use ($google, $googleHttp, $users, $user): void {
            $google->authorizationUrl();
            $googleHttp->identity = [
                'sub' => 'fixture-different-subject',
                'email' => $users->byId($user)['email'],
                'email_verified' => true,
            ];
            denied(
                fn() => $google->callback([
                    'state' => $_SESSION['google_signin']['state'],
                    'code' => 'fixture',
                ]),
            );
        },
    );
    saas_check(
        'verified Google registration and repeat sign-in use the stable subject',
        function () use ($google, $googleHttp, $users, &$ids): void {
            $google->authorizationUrl();
            $googleHttp->identity = [
                'sub' => 'fixture-google-' . bin2hex(random_bytes(6)),
                'name' => 'Google Fixture',
                'email' => 'google-' . bin2hex(random_bytes(6)) . '@example.test',
                'email_verified' => true,
            ];
            $id = $google->callback([
                'state' => $_SESSION['google_signin']['state'],
                'code' => 'fixture',
            ]);
            $ids[] = $id;
            eq(null, $users->byId($id)['onboarded_at']);
            $google->authorizationUrl();
            eq(
                $id,
                $google->callback([
                    'state' => $_SESSION['google_signin']['state'],
                    'code' => 'fixture',
                ]),
            );
        },
    );
    saas_check('onboarding completion persists without requiring payment', function () use (
        $accounts,
        $users,
        $user,
    ): void {
        $accounts->complete($user);
        eq(6, (int) $users->byId($user)['onboarding_step']);
        eq(true, $users->byId($user)['onboarded_at'] !== null);
    });
    $promoUser = $users->create(
        'Promo Fixture',
        'promo-' . bin2hex(random_bytes(7)) . '@example.test',
        'fixture-password-12345',
    );
    $promoOther = $users->create(
        'Promo Other',
        'promo-' . bin2hex(random_bytes(7)) . '@example.test',
        'fixture-password-12345',
    );
    $ids = array_merge($ids, [$promoUser, $promoOther]);
    $promos = new \App\Services\Billing\PromoCodeService();
    saas_check('invalid promo codes cannot activate a membership', function () use (
        $promos,
        $promoUser,
        $accounts,
    ): void {
        foreach (['', 'UNKNOWN001', '<script>', str_repeat('A', 65)] as $code) {
            denied(fn() => $promos->redeem($promoUser, $code));
        }
        eq(false, $accounts->membership($promoUser)['active']);
    });
    saas_check(
        'the lifetime promo upgrades a monthly member without creating a payment',
        function () use ($promos, $promoUser, $promoOther, $accounts): void {
            member($promoUser, 'monthly', date('Y-m-d H:i:s', time() + 86400));
            $promos->redeem($promoUser, '  iampoor001  ');
            eq(
                ['active' => true, 'plan' => 'lifetime', 'expires_at' => null],
                $accounts->membership($promoUser),
            );
            eq(false, $accounts->membership($promoOther)['active']);
            eq(null, (new PaymentRepository())->latest($promoUser));
            (new Entitlements())->requirePaid($promoUser);
            (new Entitlements())->requireAi($promoUser);
        },
    );
    saas_check(
        'repeat promo requests are idempotent and the code supports multiple friends',
        function () use ($promos, $promoUser, $promoOther, $accounts): void {
            $promos->redeem($promoUser, 'IAMPOOR001');
            $promos->redeem($promoOther, 'IAMPOOR001');
            $query = Database::connection()->prepare(
                'SELECT COUNT(*) FROM promo_redemptions WHERE user_id = ?',
            );
            $query->execute([$promoUser]);
            eq(1, (int) $query->fetchColumn());
            $query = Database::connection()->prepare(
                "SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND event = 'billing.promo_redeemed'",
            );
            $query->execute([$promoUser]);
            eq(1, (int) $query->fetchColumn());
            eq('lifetime', $accounts->membership($promoOther)['plan']);
        },
    );
    saas_check('disabled, expired and exhausted promo codes are rejected', function () use (
        $promos,
        $promoUser,
        &$promoIds,
    ): void {
        foreach (
            [[0, null, null], [1, date('Y-m-d H:i:s', time() - 60), null], [1, null, 0]]
            as [$enabled, $expires, $limit]
        ) {
            $code = 'FIXTURE-' . strtoupper(bin2hex(random_bytes(8)));
            Database::connection()
                ->prepare(
                    'INSERT INTO promo_codes (code_hash, label, enabled, expires_at, max_redemptions) VALUES (?, ?, ?, ?, ?)',
                )
                ->execute([hash('sha256', $code), 'Test only', $enabled, $expires, $limit]);
            $promoIds[] = (int) Database::connection()->lastInsertId();
            denied(fn() => $promos->redeem($promoUser, $code));
        }
    });
    saas_check('a delayed monthly payment cannot downgrade promo lifetime access', function () use (
        $promoUser,
        $accounts,
    ): void {
        $payments = new PaymentRepository();
        $id = $payments->create(
            $promoUser,
            'monthly',
            200,
            '254712345678',
            'sandbox',
            'test-only-callback',
        );
        $payments->started($id, 'promo-checkout-' . $id, 'promo-merchant-' . $id);
        $payments->settle($id, '0');
        eq('lifetime', $accounts->membership($promoUser)['plan']);
        eq(null, $accounts->membership($promoUser)['expires_at']);
    });
    $gmailHttp = new class extends HttpClient {
        public array $calls = [];
        public function request(
            string $method,
            string $url,
            array $headers = [],
            ?string $body = null,
            int $timeout = 45,
            int $maxBytes = 4194304,
        ): array {
            parse_str($body ?? '', $parameters);
            $this->calls[] = $parameters;
            return [
                'status' => 200,
                'headers' => [],
                'body' => json_encode([
                    'access_token' => 'fixture-gmail-access',
                    'refresh_token' => 'fixture-gmail-refresh',
                    'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
                    'expires_in' => 3600,
                ]),
            ];
        }
        public function json(
            string $method,
            string $url,
            array $data = [],
            array $headers = [],
            int $timeout = 45,
        ): array {
            return ['email' => 'gmail-fixture@example.test', 'email_verified' => true];
        }
    };
    $gmail = new \App\Services\Mail\GmailService($gmailHttp);
    putenv('GOOGLE_REDIRECT_URI=https://example.com/Callback');
    saas_check(
        'Gmail uses the registered shared callback and keeps identity sign-in separate',
        function () use ($gmail, $google, $promoUser): void {
            $google->authorizationUrl();
            parse_str(parse_url($gmail->authorizationUrl($promoUser), PHP_URL_QUERY), $query);
            eq('https://example.com/Callback', $query['redirect_uri']);
            eq('offline', $query['access_type']);
            eq(true, str_contains($query['scope'], 'gmail.send'));
            eq($promoUser, $_SESSION['google_oauth']['user_id']);
            eq(true, isset($_SESSION['google_signin']));
        },
    );
    saas_check(
        'Gmail rejects a callback for another user or an expired request before HTTP',
        function () use ($gmail, $gmailHttp, $promoUser, $promoOther): void {
            $gmail->authorizationUrl($promoUser);
            denied(
                fn() => $gmail->callback(
                    $promoOther,
                    $_SESSION['google_oauth']['state'],
                    'fixture-code',
                ),
                400,
            );
            $gmail->authorizationUrl($promoUser);
            $_SESSION['google_oauth']['expires'] = time() - 1;
            denied(
                fn() => $gmail->callback(
                    $promoUser,
                    $_SESSION['google_oauth']['state'],
                    'fixture-code',
                ),
                400,
            );
            eq([], $gmailHttp->calls);
        },
    );
    saas_check(
        'Gmail token exchange uses the original callback and stores encrypted credentials',
        function () use ($gmail, $gmailHttp, $promoUser): void {
            $gmail->authorizationUrl($promoUser);
            $state = $_SESSION['google_oauth']['state'];
            putenv('GOOGLE_REDIRECT_URI=https://example.com/changed-mid-flow');
            $gmail->callback($promoUser, $state, 'fixture-code');
            eq('https://example.com/Callback', $gmailHttp->calls[0]['redirect_uri']);
            $connection = (new \App\Repositories\IntegrationRepository())->connection($promoUser);
            eq('gmail-fixture@example.test', $connection['account_email']);
            eq(false, str_contains($connection['encrypted_tokens'], 'fixture-gmail-refresh'));
            eq(
                'fixture-gmail-refresh',
                json_decode(Crypto::decrypt($connection['encrypted_tokens']), true)[
                    'refresh_token'
                ],
            );
            denied(fn() => $gmail->callback($promoUser, $state, 'fixture-code'), 400);
            eq(1, count($gmailHttp->calls));
        },
    );
    $autoUser = $users->create(
        'Automation Fixture',
        'auto-' . bin2hex(random_bytes(7)) . '@example.test',
        'fixture-password-12345',
    );
    $ids[] = $autoUser;
    member($autoUser, 'lifetime');
    $autoSettings = new SettingsRepository();
    $autoProvider = $autoSettings->saveProvider($autoUser, 0, [
        'Automation fixture',
        'https://example.com/v1',
        Crypto::encrypt('fixture-never-live'),
        'fixture-model',
        json_encode(['input_per_million' => 0.25, 'output_per_million' => 1.5]),
    ]);
    $autoAi = $autoSettings->get($autoUser, 'ai');
    foreach (['screening_provider', 'tailoring_provider', 'letter_provider'] as $key) {
        $autoAi[$key] = $autoProvider;
    }
    $autoSettings->save($autoUser, 'ai', $autoAi);
    $jobService = new \App\Services\JobService();
    $openJob = $jobService->create($autoUser, [
        'title' => 'Automation developer',
        'employer' => 'Fixture Company',
        'description' => 'A test vacancy requiring Python and APIs.',
        'application_method' => 'external',
        'original_url' => 'https://example.com/open-' . $autoUser,
    ]);
    $closedJob = $jobService->create($autoUser, [
        'title' => 'Closed developer role',
        'employer' => 'Fixture Company',
        'description' => 'A closed test vacancy.',
        'application_method' => 'external',
        'deadline' => date('Y-m-d', time() - 86400),
        'original_url' => 'https://example.com/closed-' . $autoUser,
    ]);
    $automationService = new \App\Services\AutomationService();
    saas_check(
        'Automatic mode enables search, matching and preparation while preserving send limits',
        function () use ($autoUser, $autoSettings): void {
            (new \App\Services\SettingsService())->save($autoUser, 'automation', [
                'mode' => 'automatic',
                'confirm_automatic' => 1,
                'allow_email' => 1,
                'daily_limit' => 1,
                'minimum_match' => 80,
            ]);
            $configuration = $autoSettings->get($autoUser, 'automation');
            foreach (['discovery_enabled', 'analysis_enabled', 'preparation_enabled'] as $key) {
                eq(true, $configuration[$key]);
            }
            eq(1, $configuration['daily_limit']);
            eq(80, $configuration['minimum_match']);
            eq(['email'], $configuration['permitted_methods']);
        },
    );
    saas_check(
        'uploaded documents alone cannot start matching before the career profile is checked',
        function () use ($automationService, $autoUser): void {
            eq(false, $automationService->status($autoUser)['can_analyse']);
            eq(0, $automationService->queueSavedJobs($autoUser));
            eq(
                true,
                count(
                    array_filter(
                        $automationService->status($autoUser)['blockers'],
                        fn($item) => $item['href'] === '/profile',
                    ),
                ) > 0,
            );
        },
    );
    (new \App\Services\ProfileService())->save($autoUser, [
        'full_name' => 'Automation Fixture',
        'email' => 'automation@example.test',
        'summary' => 'A developer with Python and API experience.',
        'skills' => ['Python', 'REST APIs'],
        'verified' => 1,
    ]);
    saas_check(
        'automation backfills existing open jobs once and excludes closed vacancies',
        function () use ($automationService, $autoUser, $openJob): void {
            eq(true, $automationService->status($autoUser)['can_analyse']);
            eq(1, $automationService->queueSavedJobs($autoUser));
            eq(0, $automationService->queueSavedJobs($autoUser));
            $statement = Database::connection()->prepare(
                "SELECT payload_json FROM background_tasks WHERE user_id = ? AND type = 'analyse_job'",
            );
            $statement->execute([$autoUser]);
            $tasks = $statement->fetchAll();
            eq(1, count($tasks));
            eq($openJob['id'], json_decode($tasks[0]['payload_json'], true)['job_id']);
            eq(0, $automationService->status($autoUser)['email_jobs']);
        },
    );
    saas_check('expired jobs are rejected before an AI matching request is made', function () use (
        $autoUser,
        $closedJob,
    ): void {
        try {
            (new \App\Services\AI\MatchingService())->analyse($autoUser, $closedJob['id']);
        } catch (HttpException $exception) {
            eq(
                'This vacancy has closed. It will not be matched or prepared for automatic submission.',
                $exception->getMessage(),
            );
            return;
        }
        throw new RuntimeException('A closed vacancy was analysed.');
    });
    saas_check(
        'generated letters cannot retain template dates, identities or empty content',
        function (): void {
            $preparation = new \App\Services\Documents\PreparationService();
            foreach (
                [
                    '',
                    "[Today's Date]\nDear Hiring Manager",
                    '[Hiring Manager’s Name]',
                    '[Your Name]',
                    'Dear Hiring Team, contact (xxx) xxx-xxxx.',
                ]
                as $letter
            ) {
                denied(fn() => $preparation->validateLetter($letter));
            }
            $preparation->validateLetter(
                "20 November 2026\nDear Hiring Team,\nI build Python APIs.\nRegards,\nAutomation Fixture",
            );
        },
    );
    echo $passed . ' SaaS checks passed.' . PHP_EOL;
} finally {
    foreach ($ids as $id) {
        Database::connection()
            ->prepare('DELETE FROM applications WHERE user_id = ?')
            ->execute([$id]);
        Database::connection()
            ->prepare('DELETE FROM users WHERE id = ?')
            ->execute([$id]);
    }
    foreach ($promoIds as $id) {
        Database::connection()
            ->prepare('DELETE FROM promo_codes WHERE id = ?')
            ->execute([$id]);
    }
}
