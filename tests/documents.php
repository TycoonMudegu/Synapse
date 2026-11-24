<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationDraftService;
use App\Services\Documents\DocumentRetentionService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;
use App\Services\JobService;
use App\Services\Worker;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Psr7\Response;

require BASE_PATH . '/scripts/migrate.php';
$users = [];
$passed = 0;
$objects = [];
$rewrites = 0;
$failStorage = false;
$bytes = '%PDF-1.4 private retention fixture';

function doc_check(string $name, callable $test): void
{
    global $passed;
    $test();
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}

function doc_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function doc_rejects(callable $test, int $status): void
{
    try {
        $test();
    } catch (HttpException $error) {
        doc_same($status, $error->status);
        return;
    }
    throw new RuntimeException('Expected rejection.');
}

// Exercise the real Google SDK request/response format without cloud access.
$client = new StorageClient([
    'projectId' => 'retention-fixture',
    'credentialsFetcher' => new InsecureCredentials(),
    'retries' => 0,
    'httpHandler' => function ($request) use (&$objects, &$rewrites, &$failStorage) {
        if ($failStorage) {
            throw new \GuzzleHttp\Exception\ServerException(
                'Fixture storage unavailable',
                $request,
                new Response(
                    503,
                    [],
                    '{"error":{"message":"Fixture storage unavailable","code":503}}',
                ),
            );
        }
        $path = $request->getUri()->getPath();
        if (!preg_match('#/b/([^/]+)/o/([^/]+)(?:/rewriteTo/.*)?$#', $path, $matches)) {
            throw new RuntimeException('Unexpected storage request: ' . $path);
        }
        $name = rawurldecode($matches[2]);
        $info = $objects[$name] ?? null;
        if (!$info) {
            return new Response(404, [], '{"error":{"message":"Not found","code":404}}');
        }
        if ($request->getMethod() === 'GET') {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($info));
        }
        if ($request->getMethod() !== 'POST' || !str_contains($path, '/rewriteTo/')) {
            throw new RuntimeException('Unexpected storage mutation.');
        }
        parse_str($request->getUri()->getQuery(), $query);
        doc_same($info['generation'], $query['ifGenerationMatch'] ?? null);
        doc_same($info['generation'], $query['ifSourceGenerationMatch'] ?? null);
        $body = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
        doc_same('private, no-store', $body['cacheControl'] ?? null);
        $rewrites++;
        $info = array_replace($info, $body, [
            'generation' => (string) ((int) $info['generation'] + 1),
        ]);
        $objects[$name] = $info;
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'done' => true,
                'totalBytesRewritten' => $info['size'],
                'objectSize' => $info['size'],
                'resource' => $info,
            ]),
        );
    },
]);
$storage = new DocumentStorage($client);
$retention = new DocumentRetentionService($storage);
$documents = new DocumentRepository();
$applications = new ApplicationRepository();
$db = Database::connection();

try {
    foreach (['owner', 'other'] as $name) {
        $users[] = (new UserRepository())->create(
            'Retention ' . $name,
            'retention-' . bin2hex(random_bytes(8)) . '@example.test',
            bin2hex(random_bytes(20)),
        );
    }
    [$user, $other] = $users;
    $makeDocument = function (string $kind = 'tailored_cv') use (
        $documents,
        $user,
        &$objects,
        $bytes,
    ): int {
        $name = 'documents/' . $user . '/' . bin2hex(random_bytes(24)) . '.pdf';
        $id = $documents->create($user, [
            'name' => 'Retention fixture.pdf',
            'kind' => $kind,
            'storage_path' => $name,
            'storage_driver' => 'gcs',
            'storage_bucket' => 'retention-fixture',
            'storage_generation' => '100',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'extracted_text' => 'Fixture text',
        ]);
        $objects[$name] = [
            'name' => $name,
            'bucket' => 'retention-fixture',
            'generation' => '100',
            'size' => (string) strlen($bytes),
            'md5Hash' => base64_encode(md5($bytes, true)),
            'storageClass' => 'STANDARD',
            'contentType' => 'application/pdf',
        ];
        return $id;
    };
    $makeApplication = function (
        int $documentId,
        string $status = 'submitted',
        string $age = '-4 days',
    ) use ($user, $applications): int {
        $jobId = (new JobService())->create($user, [
            'title' => 'Retention fixture ' . bin2hex(random_bytes(5)),
            'employer' => 'Fixture employer',
            'description' => 'Synthetic retention fixture.',
            'verified' => true,
        ])['id'];
        $id = $applications->create($user, (new JobRepository())->get($user, $jobId));
        $applications->update($user, $id, [
            'cv_document_id' => $documentId,
            'status' => $status,
            'submitted_at' => $status === 'draft' ? null : date('Y-m-d H:i:s', strtotime($age)),
            'confirmation' => $status === 'draft' ? null : 'Fixture confirmed submission',
        ]);
        return $id;
    };
    $eligible = $makeDocument();
    $makeApplication($eligible);
    $favorite = $makeDocument();
    $makeApplication($favorite);
    $retention->favorite($user, $favorite, true);
    $recent = $makeDocument();
    $makeApplication($recent, 'submitted', '-2 days 23 hours');
    $boundary = $makeDocument();
    $makeApplication($boundary, 'submitted', '-3 days');
    $draft = $makeDocument();
    $makeApplication($draft, 'draft');
    $ambiguous = $makeDocument();
    $makeApplication($ambiguous, 'awaiting_external_action');
    $shared = $makeDocument();
    $makeApplication($shared);
    $makeApplication($shared, 'draft');
    $sharedRecent = $makeDocument();
    $makeApplication($sharedRecent);
    $makeApplication($sharedRecent, 'submitted', '-1 hour');
    $master = $makeDocument();
    $documents->addCv($user, $master);
    $documents->master($user, $master);
    $makeApplication($master);
    $uploaded = $makeDocument('master_cv');
    $makeApplication($uploaded);
    $template = $makeDocument('cover_letter_template');
    $makeApplication($template);
    $preference = $makeDocument();
    $makeApplication($preference);
    (new \App\Repositories\AccountRepository())->savePreferences($user, [
        'cv_document_id' => $preference,
    ]);
    $missingConfirmation = $makeDocument();
    $noConfirmationApplication = $makeApplication($missingConfirmation);
    $applications->update($user, $noConfirmationApplication, ['confirmation' => null]);
    $processing = $makeDocument();
    $makeApplication($processing);
    $db->prepare("UPDATE documents SET processing_status = 'pending' WHERE id = ?")->execute([
        $processing,
    ]);
    $unattached = $makeDocument();

    doc_check(
        'three-day retention protects favorites, templates, master CVs, drafts, recent and unconfirmed submissions',
        function () use ($documents, $user, $eligible, $boundary, $other) {
            doc_same(
                [$eligible, $boundary],
                array_column($documents->archiveCandidates($user), 'id'),
            );
            doc_same([], $documents->archiveCandidates($other));
        },
    );
    doc_check('favorites are owned, idempotent and filterable', function () use (
        $retention,
        $documents,
        $user,
        $other,
        $favorite,
    ) {
        $retention->favorite($user, $favorite, true);
        doc_same([$favorite], array_column($documents->all($user, true), 'id'));
        doc_same([], $documents->all($other, true));
        doc_rejects(fn() => $retention->favorite($other, $favorite, true), 404);
    });
    putenv('DOCUMENT_STORAGE=gcs');
    putenv('GCS_COLD_STORAGE_CLASS=COLDLINE');
    doc_check(
        'archiving rewrites the actual cloud storage class and saves its verified generation',
        function () use ($retention, $documents, $user, $eligible, &$objects, &$rewrites) {
            doc_same(true, $retention->archive($user, $eligible));
            $document = $documents->get($user, $eligible);
            doc_same('COLDLINE', $document['storage_class']);
            doc_same('101', $document['storage_generation']);
            doc_same(true, !empty($document['archived_at']));
            doc_same('COLDLINE', $objects[$document['storage_path']]['storageClass']);
            $count = $rewrites;
            doc_same(false, $retention->archive($user, $eligible));
            doc_same($count, $rewrites);
        },
    );
    doc_check(
        'cold documents block reads, signed URLs and reuse in application drafts',
        function () use ($storage, $documents, $user, $eligible, $makeApplication) {
            $document = $documents->get($user, $eligible);
            doc_rejects(fn() => $storage->read($document), 409);
            doc_rejects(fn() => $storage->downloadUrl($document, false), 409);
            doc_rejects(fn() => (new DocumentService())->path($document), 409);
            doc_rejects(fn() => $documents->master($user, $eligible), 409);
            $application = $makeApplication($eligible, 'draft');
            doc_rejects(
                fn() => (new ApplicationDraftService())->save($user, $application, [
                    'subject' => 'Fixture',
                    'cv_document_id' => $eligible,
                ]),
                409,
            );
        },
    );
    doc_check('favoriting a cold document restores Standard storage and access', function () use (
        $retention,
        $documents,
        $user,
        $eligible,
        &$objects,
    ) {
        $retention->favorite($user, $eligible, true);
        $document = $documents->get($user, $eligible);
        doc_same(1, $document['is_favorite']);
        doc_same(null, $document['archived_at']);
        doc_same('STANDARD', $objects[$document['storage_path']]['storageClass']);
        doc_same('102', $document['storage_generation']);
        DocumentStorage::assertAvailable($document);
        doc_same([], $documents->archiveCandidates($user, $eligible));
    });
    doc_check(
        'failed cloud transitions leave document availability and favorites unchanged',
        function () use ($retention, $documents, $user, $boundary, &$failStorage) {
            $failStorage = true;
            doc_rejects(fn() => $retention->archive($user, $boundary), 503);
            doc_same(null, $documents->get($user, $boundary)['archived_at']);
            $failStorage = false;
            doc_same(true, $retention->archive($user, $boundary));
            $failStorage = true;
            doc_rejects(fn() => $retention->favorite($user, $boundary, true), 503);
            $document = $documents->get($user, $boundary);
            doc_same(0, $document['is_favorite']);
            doc_same(true, !empty($document['archived_at']));
            $failStorage = false;
        },
    );
    doc_check(
        'a rewrite completed before an interrupted DB commit is recovered without another rewrite',
        function () use (
            $makeDocument,
            $makeApplication,
            $storage,
            $retention,
            $documents,
            $user,
            &$rewrites,
        ) {
            $id = $makeDocument();
            $makeApplication($id);
            $storage->changeClass($documents->get($user, $id), 'COLDLINE');
            $count = $rewrites;
            doc_same(true, $retention->archive($user, $id));
            doc_same($count, $rewrites);
            doc_same('101', $documents->get($user, $id)['storage_generation']);
            // The same recovery applies to an interrupted restoration.
            $storage->changeClass($documents->get($user, $id), 'STANDARD');
            $retention->favorite($user, $id, true);
            doc_same('102', $documents->get($user, $id)['storage_generation']);
            doc_same(null, $documents->get($user, $id)['archived_at']);
        },
    );
    doc_check(
        'cloud generations changed outside a verified transition are rejected',
        function () use ($makeDocument, $documents, $storage, $user, &$objects) {
            $id = $makeDocument();
            $document = $documents->get($user, $id);
            $objects[$document['storage_path']]['generation'] = '999';
            doc_rejects(fn() => $storage->changeClass($document, 'COLDLINE'), 409);
        },
    );
    doc_check(
        'retention tasks recheck eligibility and run for paused free accounts',
        function () use ($user, $favorite, $documents, $retention) {
            $queue = new QueueRepository();
            $task = $queue->enqueue($user, 'archive_document', ['document_id' => $favorite]);
            doc_same(true, (new Worker(['archive_document']))->tick());
            doc_same('completed', $queue->get($user, $task)['status']);
            doc_same(null, $documents->get($user, $favorite)['archived_at']);
            $retention->favorite($user, $favorite, false);
            doc_same(
                [$favorite],
                array_column($documents->archiveCandidates($user, $favorite), 'id'),
            );
        },
    );
    doc_check(
        'favoriting recovers an archive rewrite interrupted before the archived flag was committed',
        function () use ($makeDocument, $makeApplication, $storage, $retention, $documents, $user) {
            $id = $makeDocument();
            $makeApplication($id);
            $storage->changeClass($documents->get($user, $id), 'COLDLINE');
            $retention->favorite($user, $id, true);
            $document = $documents->get($user, $id);
            doc_same(1, $document['is_favorite']);
            doc_same(null, $document['archived_at']);
            doc_same('STANDARD', $document['storage_class']);
            doc_same('102', $document['storage_generation']);
        },
    );
    echo $passed . ' document checks passed.' . PHP_EOL;
} finally {
    // Fixture objects only live in memory; no test ever contacts the live bucket.
    foreach ($users as $id) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
