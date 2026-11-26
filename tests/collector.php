<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require BASE_PATH . '/scripts/migrate.php';

use App\Controllers\CollectorController;
use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\CatalogueRepository;
use App\Repositories\CollectorRepository;
use App\Repositories\JobRepository;
use App\Repositories\UserRepository;
use App\Services\Discovery\CatalogueDiscovery;
use App\Services\Discovery\CollectorSourceService;
use App\Services\Discovery\CloudRunCollector;
use App\Services\JobService;
use App\Services\Security\Admin;

putenv('COLLECTOR_TOKEN=' . str_repeat('f', 64));
putenv('COLLECTOR_MAX_DAILY_RUNS=20');
$db = Database::connection();
$runIds = $userIds = $jobIds = [];
$tag = bin2hex(random_bytes(6));
$passed = 0;
function verify(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
    echo 'PASS ' . $message . PHP_EOL;
}
function refuses(callable $operation, int $status): void
{
    try {
        $operation();
    } catch (HttpException $error) {
        verify($error->status === $status, 'operation rejected with HTTP ' . $status);
        return;
    }
    throw new RuntimeException('Expected operation to be rejected.');
}
function age_run(int $id): void
{
    Database::connection()
        ->prepare(
            'UPDATE collector_runs SET created_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE id = ?',
        )
        ->execute([$id]);
}
final class CollectorCloudFixture extends App\Http\HttpClient
{
    public array $calls = [];
    public function json(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = 45,
    ): array {
        $this->calls[] = [$method, $url, $data, $headers];
        return ['name' => 'fixture-operation'];
    }
}
try {
    putenv('COLLECTOR_PROJECT=fixture-project');
    putenv('COLLECTOR_REGION=us-central1');
    putenv('COLLECTOR_JOB=synapse-job-collector');
    $cloud = new CollectorCloudFixture();
    (new CloudRunCollector($cloud, static fn(): string => 'fixture-access-token'))->dispatch(42);
    verify(
        $cloud->calls[0][1] ===
            'https://run.googleapis.com/v2/projects/fixture-project/locations/us-central1/jobs/synapse-job-collector:run',
        'admin collection invokes the fixed Cloud Run Jobs API',
    );
    verify(
        $cloud->calls[0][2]['overrides']['containerOverrides'][0]['env'] === [
            ['name' => 'COLLECTOR_RUN_ID', 'value' => '42'],
        ],
        'manual execution receives only its reserved run identity',
    );
    putenv('COLLECTOR_PROJECT=');
    $users = new UserRepository();
    $owner = $users->create(
        'Collector fixture owner',
        'collector-' . $tag . '@example.test',
        'test-password-123456',
    );
    $userIds[] = $owner;
    $other = $users->create(
        'Collector fixture user',
        'collector-other-' . $tag . '@example.test',
        'test-password-123456',
    );
    $userIds[] = $other;
    verify(
        !Admin::allowed($users->byId($owner)) && !Admin::allowed(null),
        'new accounts receive no administrator privilege',
    );
    $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$owner]);
    verify(
        Admin::allowed($users->byId($owner)) && !Admin::allowed($users->byId($other)),
        'administrator access is explicit and account-specific',
    );
    refuses(fn() => CollectorController::authenticate(''), 401);
    refuses(fn() => CollectorController::authenticate('Bearer wrong'), 401);
    CollectorController::authenticate('Bearer ' . str_repeat('f', 64));
    verify(true, 'collector bearer credential is validated without browser sessions');
    refuses(
        fn() => (new CollectorSourceService())->save(null, [
            'name' => 'Unsafe fixture',
            'adapter' => 'json',
            'url' => 'https://169.254.169.254/computeMetadata/v1/',
        ]),
        422,
    );

    $collector = new CollectorRepository();
    $source = $collector->sources()[0];
    $sourceId = (int) $source['id'];
    $db->prepare('UPDATE collector_sources SET max_jobs = 1 WHERE id = ?')->execute([$sourceId]);
    $run = $collector->request($owner, $sourceId);
    $runIds[] = $run;
    refuses(fn() => $collector->request($owner, null), 409);
    $claim = $collector->claim($run, 'fixture-' . $tag);
    verify(
        $claim['sources'][0]['max_jobs'] === 1,
        'the execution receives a bounded source snapshot',
    );
    verify(
        $claim == $collector->claim($run, 'fixture-' . $tag),
        'repeated claims from the same execution are idempotent',
    );
    refuses(fn() => $collector->claim($run, 'other-execution'), 409);
    $job = [
        'title' => 'PHP Developer ' . $tag,
        'employer' => 'Collector fixture ' . $tag,
        'description' => 'Fixture vacancy, not a real job.',
        'location' => 'Nairobi, Kenya',
        'original_url' => 'https://example.com/jobs/' . $tag,
    ];
    $result = $collector->batch($run, $claim['run_token'], $sourceId, 'chunk-0', [$job]);
    verify(
        $result['inserted'] === 1 && !$result['replayed'],
        'the collector imports one shared vacancy',
    );
    $entry = (new CatalogueRepository())->list($tag, true)[0];
    $jobId = (int) $entry['id'];
    $jobIds[] = $jobId;
    $replay = $collector->batch($run, $claim['run_token'], $sourceId, 'chunk-0', [$job]);
    verify(
        $replay['replayed'] && (int) $collector->run($run)['inserted_count'] === 1,
        'batch replay neither duplicates jobs nor inflates counters',
    );
    refuses(
        fn() => $collector->batch($run, $claim['run_token'], $sourceId, 'chunk-0', [
            [...$job, 'description' => 'Different payload.'],
        ]),
        409,
    );
    refuses(
        fn() => $collector->batch($run, $claim['run_token'], $sourceId, 'chunk-1', [$job]),
        429,
    );
    refuses(
        fn() => $collector->batch($run, str_repeat('0', 64), $sourceId, 'chunk-1', [$job]),
        403,
    );
    refuses(
        fn() => $collector->batch($run, $claim['run_token'], $sourceId + 1, 'chunk-0', [$job]),
        403,
    );
    verify(
        $collector->finish($run, $claim['run_token'], [
            ['source_id' => $sourceId, 'status' => 'completed', 'error' => ''],
        ])['status'] === 'completed',
        'run completion records actual source results',
    );
    verify(
        $collector->finish($run, $claim['run_token'], [])['status'] === 'completed',
        'a repeated completion does not rewrite the run',
    );
    refuses(fn() => $collector->request($owner, null), 429);

    $catalogue = new CatalogueRepository();
    $savedA = $catalogue->saveForUser($jobId, $owner);
    $savedB = $catalogue->saveForUser($jobId, $other);
    verify(
        $savedA['id'] !== $savedB['id'] && $catalogue->saveForUser($jobId, $owner)['duplicate'],
        'users receive separate, deduplicated saved copies',
    );
    verify(
        (new JobRepository())->get($owner, $savedA['id'])['verified_at'] === null,
        'collected vacancies do not bypass factual approval',
    );
    verify(
        (new JobRepository())->get($owner, $savedA['id'])['source_name'] === $source['name'],
        'saved vacancies retain shared-source provenance',
    );
    refuses(fn() => (new JobRepository())->get($other, $savedA['id']), 404);

    $normal = (new JobService())->normalise([
        ...$job,
        'description' => 'Reviewed admin correction.',
    ]);
    Database::transaction(
        fn() => $catalogue->upsert($normal, null, true, $jobId, (int) $entry['revision'], false),
    );
    refuses(
        fn() => Database::transaction(
            fn() => $catalogue->upsert($normal, null, true, $jobId, (int) $entry['revision'], true),
        ),
        409,
    );
    refuses(fn() => $catalogue->get($jobId, true), 404);
    age_run($run);
    $refresh = $collector->request($owner, $sourceId);
    $runIds[] = $refresh;
    $newClaim = $collector->claim($refresh, 'fixture-refresh-' . $tag);
    $collector->batch($refresh, $newClaim['run_token'], $sourceId, 'chunk-0', [
        [...$job, 'description' => 'Later source content.'],
    ]);
    $entry = $catalogue->get($jobId);
    verify(
        $entry['job']['description'] === 'Reviewed admin correction.' &&
            !(bool) $entry['published'],
        'collection preserves admin edits and unpublishing',
    );
    $collector->finish($refresh, $newClaim['run_token'], [
        [
            'source_id' => $sourceId,
            'status' => 'failed',
            'error' => 'Fixture failure after partial import.',
        ],
    ]);
    verify(
        $collector->run($refresh)['status'] === 'failed' && $catalogue->get($jobId)['id'] == $jobId,
        'source failure retains previously imported jobs',
    );
    age_run($refresh);
    $catalogue->publish($jobId, true);
    $settings = new App\Repositories\SettingsRepository();
    $settings->save($other, 'discovery', [
        'keywords' => 'a-title-that-does-not-exist',
        'locations' => 'Kenya',
    ]);
    verify(
        (new CatalogueDiscovery())->run($other) === 0,
        'central discovery applies user filters without contacting sources',
    );

    putenv('COLLECTOR_MAX_DAILY_RUNS=1');
    refuses(fn() => $collector->request($owner, null), 429);
    putenv('COLLECTOR_MAX_DAILY_RUNS=20');
    $scheduled = $collector->claim(null, 'fixture-scheduled-' . $tag);
    $runIds[] = $scheduled['run_id'];
    $results = array_map(
        static fn(array $source): array => [
            'source_id' => $source['id'],
            'status' => 'completed',
            'error' => '',
        ],
        $scheduled['sources'],
    );
    $collector->finish($scheduled['run_id'], $scheduled['run_token'], $results);
    verify(
        $collector->claim(null, 'fixture-duplicate-' . $tag)['skip'],
        'duplicate daily scheduled executions exit without collecting again',
    );
    echo $passed . " collector checks passed.\n";
} finally {
    foreach ($runIds as $id) {
        $db->prepare('DELETE FROM collector_runs WHERE id = ?')->execute([$id]);
    }
    foreach ($jobIds as $id) {
        $db->prepare('DELETE FROM shared_jobs WHERE id = ?')->execute([$id]);
    }
    foreach ($userIds as $id) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    if (isset($sourceId)) {
        $db->prepare(
            'UPDATE collector_sources SET max_jobs = 20, health = ?, last_error = NULL WHERE id = ?',
        )->execute(['untested', $sourceId]);
    }
}
