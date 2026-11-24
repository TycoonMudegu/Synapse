<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\DocumentRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\Documents\DocumentService;
use App\Services\Documents\Exporter;
use App\Services\Documents\LayoutPreparer;
use App\Services\Documents\RendererClient;
use App\Services\Documents\SourceExporter;

require BASE_PATH . '/scripts/migrate.php';
$passed = 0;
$users = [];
function layout_check(string $name, callable $test): void
{
    global $passed;
    $test();
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}
function layout_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}
function layout_denied(callable $test, int $status = 422): void
{
    try {
        $test();
    } catch (HttpException $error) {
        layout_same($status, $error->status);
        return;
    }
    throw new RuntimeException('Expected rejection.');
}

final class LayoutRetryProvider implements \App\Services\AI\Provider
{
    public array $inputs = [];

    public function generate(array $configuration, array $messages, array $schema): array
    {
        $input = json_decode($messages[1]['content'], true, 64, JSON_THROW_ON_ERROR);
        $this->inputs[] = $input;
        $identity = $input['profile']['full_name'] . ' ' . $input['profile']['email'];
        return [
            'data' => [
                'edits' => [
                    [
                        'part' => 'word/document.xml',
                        'index' => 0,
                        'original' => 'Old identity',
                        'text' => $identity,
                    ],
                    [
                        'part' => 'word/document.xml',
                        'index' => 1,
                        'original' => str_repeat('Source text. ', 20),
                        'text' => count($this->inputs) === 1 ? 'First draft.' : 'Short draft.',
                    ],
                ],
                'uncertainties' => [],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ];
    }
}

final class LayoutRetryHttp extends HttpClient
{
    public int $letterAttempts = 0;

    public function __construct(
        private readonly string $letterHash,
        private readonly int $failures = 1,
    ) {}

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 45,
        int $maxBytes = 4194304,
    ): array {
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $source = base64_decode($payload['document'], true);
        if (str_ends_with($url, '/inspect-docx')) {
            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'sha256' => hash('sha256', $source),
                    'paragraphs' => [
                        [
                            'part' => 'word/document.xml',
                            'index' => 0,
                            'text' => 'Old identity',
                            'editable' => true,
                        ],
                        [
                            'part' => 'word/document.xml',
                            'index' => 1,
                            'text' => str_repeat('Source text. ', 20),
                            'editable' => true,
                        ],
                    ],
                ]),
            ];
        }
        if (
            hash('sha256', $source) === $this->letterHash &&
            ++$this->letterAttempts <= $this->failures
        ) {
            return [
                'status' => 409,
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"error":"layout_overflow"}',
            ];
        }
        return [
            'status' => 200,
            'headers' => ['content-type' => 'application/pdf'],
            'body' => '%PDF-fixture',
        ];
    }
}

final class LayoutHttp extends HttpClient
{
    public array $calls = [];
    public array $response = [
        'status' => 200,
        'headers' => ['content-type' => 'application/pdf'],
        'body' => '%PDF-fixture',
    ];
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 45,
        int $maxBytes = 4194304,
    ): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout', 'maxBytes');
        return $this->response;
    }
}
try {
    $user = (new UserRepository())->create(
        'Layout Fixture',
        'layout-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $users[] = $user;
    $other = (new UserRepository())->create(
        'Other Layout',
        'layout-other-' . bin2hex(random_bytes(8)) . '@example.test',
        'fixture-password-12345',
    );
    $users[] = $other;
    $http = new LayoutHttp();
    $client = new RendererClient($http);
    layout_check(
        'preserving uploaded designs is the default and missing renderer setup fails clearly',
        function () use ($user, $client): void {
            layout_same(
                'preserve',
                (new SettingsRepository())->get($user, 'documents')['layout_mode'],
            );
            layout_same(false, $client->configured());
            layout_denied(fn() => $client->html('<p>Test</p>'), 503);
        },
    );
    putenv('DOCUMENT_RENDERER_URL=https://renderer.example.test');
    putenv('DOCUMENT_RENDERER_TOKEN=fixture-renderer-token-123456789012345');
    layout_check(
        'the PHP client sends private authorization and bounded render requests only to the configured service',
        function () use ($client, $http): void {
            layout_same('%PDF-fixture', $client->docx('PK-source', [], 'pdf'));
            $call = $http->calls[0];
            layout_same('https://renderer.example.test/v1/render-docx', $call['url']);
            layout_same('POST', $call['method']);
            layout_same(70, $call['timeout']);
            layout_same(20 * 1024 * 1024, $call['maxBytes']);
            layout_same(
                true,
                in_array(
                    'Authorization: Bearer fixture-renderer-token-123456789012345',
                    $call['headers'],
                    true,
                ),
            );
            layout_same('PK-source', base64_decode(json_decode($call['body'], true)['document']));
        },
    );
    layout_check(
        'renderer configuration rejects insecure origins and embedded credentials',
        function () use ($client): void {
            foreach (
                [
                    'http://renderer.example.test',
                    'https://user@renderer.example.test',
                    'https://renderer.example.test?token=secret',
                    'https://renderer.example.test:8080',
                ]
                as $url
            ) {
                putenv('DOCUMENT_RENDERER_URL=' . $url);
                layout_same(false, $client->configured());
            }
            putenv('DOCUMENT_RENDERER_URL=https://renderer.example.test');
        },
    );
    layout_check('inspection is bound to the exact uploaded DOCX bytes', function () use (
        $client,
        $http,
    ): void {
        $http->response = [
            'status' => 200,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'sha256' => hash('sha256', 'PK-source'),
                'paragraphs' => [
                    [
                        'part' => 'word/document.xml',
                        'index' => 2,
                        'text' => 'Source summary.',
                        'editable' => true,
                    ],
                ],
            ]),
        ];
        layout_same('Source summary.', $client->inspect('PK-source')['paragraphs'][0]['text']);
        layout_denied(fn() => $client->inspect('PK-different'), 502);
    });
    layout_check(
        'wrong document MIME or bytes cannot be saved as successful output',
        function () use ($client, $http): void {
            $http->response = [
                'status' => 200,
                'headers' => ['content-type' => 'text/html'],
                'body' => '<h1>Error</h1>',
            ];
            layout_denied(fn() => $client->html('fixture'), 502);
            $http->response['headers']['content-type'] = 'application/pdf';
            layout_denied(fn() => $client->html('fixture'), 502);
        },
    );
    layout_check(
        'overflow and busy responses become useful errors without exposing provider bodies',
        function () use ($client, $http): void {
            $http->response = [
                'status' => 409,
                'headers' => [],
                'body' => '{"error":"layout_overflow","private":"fixture-secret-do-not-return"}',
            ];
            try {
                $client->html('fixture');
                throw new RuntimeException('Expected overflow.');
            } catch (HttpException $error) {
                layout_same(409, $error->status);
                layout_same(true, str_contains($error->getMessage(), 'adds pages'));
                layout_same(false, str_contains($error->getMessage(), 'fixture-secret'));
            }
            $http->response = ['status' => 429, 'headers' => [], 'body' => '{"error":"busy"}'];
            layout_denied(fn() => $client->html('fixture'), 429);
        },
    );
    layout_check(
        'AI edits must identify the exact editable paragraph without duplicate or multiline replacements',
        function (): void {
            $preparer = new LayoutPreparer();
            $source = [
                [
                    'part' => 'word/document.xml',
                    'index' => 2,
                    'text' => 'Original.',
                    'editable' => true,
                ],
            ];
            $edit = [
                'part' => 'word/document.xml',
                'index' => 2,
                'original' => 'Original.',
                'text' => 'Revised.',
            ];
            $preparer->validateEdits($source, [$edit]);
            foreach (
                [
                    [array_replace($edit, ['original' => 'Stale.'])],
                    [$edit, $edit],
                    [array_replace($edit, ['part' => '../secret.xml'])],
                    [array_replace($edit, ['text' => "New\nparagraph"])],
                ]
                as $edits
            ) {
                layout_denied(fn() => $preparer->validateEdits($source, $edits));
            }
            $source[0]['editable'] = false;
            layout_denied(fn() => $preparer->validateEdits($source, [$edit]));
        },
    );
    $repository = new DocumentRepository();
    $profile = (new \App\Repositories\ProfileRepository())->get($user);
    $sourceId = (new Exporter())->cv($user, $profile, 'docx', 'Source style fixture');
    $source = $repository->get($user, $sourceId);
    $sourceBytes = file_get_contents((new DocumentService())->path($source));
    layout_check(
        'DOCX exports preserve the original package byte for byte without requiring a rendering server',
        function () use ($user, $source, $sourceBytes, $repository): void {
            putenv('DOCUMENT_RENDERER_URL=');
            $id = (new SourceExporter())->export(
                $user,
                $source,
                'docx',
                'Retained design',
                snapshot: ['profile' => []],
            );
            $document = $repository->get($user, $id);
            layout_same($sourceBytes, file_get_contents((new DocumentService())->path($document)));
            layout_same((int) $source['id'], $repository->cvSnapshot($user, $id)['source_cv_id']);
            layout_same('preserve', $repository->cvSnapshot($user, $id)['layout_mode']);
        },
    );
    layout_check('preserved source export enforces document ownership', function () use (
        $other,
        $source,
    ): void {
        layout_denied(
            fn() => (new SourceExporter())->export($other, $source, 'docx', 'Forbidden'),
            404,
        );
    });
    layout_check(
        'PDF-only sources remain unchanged and cannot be flattened into DOCX',
        function () use ($user, $profile, $repository): void {
            $pdfId = (new Exporter())->cv($user, $profile, 'pdf', 'PDF source fixture');
            $source = $repository->get($user, $pdfId);
            $id = (new SourceExporter())->export($user, $source, 'pdf', 'Unchanged PDF');
            layout_same(
                file_get_contents((new DocumentService())->path($source)),
                file_get_contents((new DocumentService())->path($repository->get($user, $id))),
            );
            layout_denied(
                fn() => (new SourceExporter())->export($user, $source, 'docx', 'Cannot edit PDF'),
            );
        },
    );
    layout_check(
        'stored renderer artifacts retain their version lineage and private file mode',
        function () use ($user, $sourceId, $sourceBytes, $repository): void {
            $id = (new Exporter())->rendered(
                $user,
                $sourceBytes,
                'docx',
                'Versioned fixture',
                'tailored_cv',
                'Fixture text.',
                $sourceId,
            );
            $document = $repository->get($user, $id);
            layout_same($sourceId, (int) $document['parent_id']);
            layout_same(2, (int) $document['version']);
            layout_same(0, fileperms((new DocumentService())->path($document)) & 7);
        },
    );
    $repository->addCv($user, $sourceId);
    $repository->master($user, $sourceId);
    $templateId = (new Exporter())->letter(
        $user,
        'Original cover letter.',
        'docx',
        'Retry template',
    );
    $template = $repository->get($user, $templateId);
    $templateBytes = file_get_contents((new DocumentService())->path($template));
    (new Exporter())->rendered(
        $user,
        $templateBytes,
        'docx',
        'Editable retry template',
        'cover_letter_template',
        'Original cover letter.',
    );
    $settings = new SettingsRepository();
    $providerId = $settings->saveProvider($user, 0, [
        'Layout fixture',
        'https://provider.example.test',
        \App\Services\Security\Crypto::encrypt('fixture-key'),
        'fixture-model',
        json_encode(['input_per_million' => 1, 'output_per_million' => 1, 'max_tokens' => 1000]),
    ]);
    $settings->save($user, 'ai', ['letter_provider' => $providerId]);
    putenv('DOCUMENT_RENDERER_URL=https://renderer.example.test');
    foreach ([1, 2] as $failures) {
        layout_check(
            $failures === 1
                ? 'a letter that adds pages is shortened once using the original paragraphs and tighter budgets'
                : 'persistent letter overflow stops after the one permitted shortening retry',
            function () use ($user, $profile, $templateBytes, $failures): void {
                $provider = new LayoutRetryProvider();
                $http = new LayoutRetryHttp(hash('sha256', $templateBytes), $failures);
                $profile = array_replace($profile, [
                    'full_name' => 'Layout Fixture',
                    'email' => 'layout-fixture@example.test',
                    'summary' => 'Verified fixture summary.',
                ]);
                $preparer = new LayoutPreparer(
                    new RendererClient($http),
                    new \App\Services\AI\AiService($provider),
                );
                $build = fn() => $preparer->build(
                    $user,
                    $profile,
                    ['title' => 'Fixture role', 'employer' => 'Fixture company'],
                    'pdf',
                );
                if ($failures === 1) {
                    $result = $build();
                    layout_same('%PDF-fixture', $result['letter_bytes']);
                    layout_same(true, str_contains($result['letter_text'], 'Short draft.'));
                } else {
                    layout_denied($build, 409);
                }
                layout_same(2, count($provider->inputs));
                layout_same(2, $http->letterAttempts);
                layout_same(
                    true,
                    $provider->inputs[1]['paragraphs'][1]['maximum_text_characters'] <
                        $provider->inputs[0]['paragraphs'][1]['maximum_text_characters'],
                );
                layout_same(false, empty($provider->inputs[1]['previous_edits']));
            },
        );
    }
} finally {
    putenv('DOCUMENT_RENDERER_URL=');
    putenv('DOCUMENT_RENDERER_TOKEN=');
    foreach ($users as $userId) {
        foreach ((new DocumentRepository())->all($userId) as $document) {
            $path = (new DocumentService())->path($document);
            if (is_file($path)) {
                unlink($path);
            }
        }
        Database::connection()
            ->prepare('DELETE FROM users WHERE id = ?')
            ->execute([$userId]);
    }
}
echo "\n$passed layout-preservation checks passed. No cloud service was called.\n";
