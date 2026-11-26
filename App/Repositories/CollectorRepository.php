<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Services\JobService;

final class CollectorRepository extends Repository
{
    public function sources(bool $enabledOnly = false): array
    {
        return $this->rows(
            'SELECT * FROM collector_sources' .
                ($enabledOnly ? ' WHERE enabled = 1 AND permission_confirmed = 1' : '') .
                ' ORDER BY id LIMIT 50',
        );
    }

    public function source(int $id): array
    {
        return $this->one('SELECT * FROM collector_sources WHERE id = ?', [$id]) ??
            throw new HttpException('Source not found.', 404);
    }

    public function saveSource(?int $id, array $source): int
    {
        if ($id !== null) {
            $this->source($id);
            $this->query(
                "UPDATE collector_sources SET name = ?, adapter = ?, url = ?, config_json = ?, enabled = ?, permission_confirmed = ?, max_jobs = ?, health = 'untested', last_error = NULL WHERE id = ?",
                [...array_values($source), $id],
            );
            return $id;
        }
        $this->query(
            'INSERT INTO collector_sources (name, adapter, url, config_json, enabled, permission_confirmed, max_jobs, source_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [...array_values($source), 'custom-' . bin2hex(random_bytes(8))],
        );
        return (int) $this->db->lastInsertId();
    }

    public function run(int $id, bool $lock = false): array
    {
        return $this->one(
            'SELECT * FROM collector_runs WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$id],
        ) ?? throw new HttpException('Collection run not found.', 404);
    }

    public function runs(): array
    {
        $this->expireStale();
        return $this->rows(
            'SELECT r.*, s.name AS source_name FROM collector_runs r LEFT JOIN collector_sources s ON s.id = r.source_id ORDER BY r.id DESC LIMIT 30',
        );
    }

    public function dispatchError(int $id): void
    {
        // An ambiguous API response must never cause a second paid execution automatically.
        $this->query(
            "UPDATE collector_runs SET status = 'dispatch_uncertain', error_summary = 'Execution could not be confirmed. Check Cloud Run before retrying; this reservation expires after 30 minutes.' WHERE id = ? AND status = 'queued'",
            [$id],
        );
    }

    private function expireStale(): void
    {
        $this->query(
            "UPDATE collector_runs SET status = 'failed', completed_at = NOW(), error_summary = 'Collection did not report completion within 30 minutes. Imported batches were retained.' WHERE status IN ('queued', 'running', 'dispatch_uncertain') AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
        );
    }

    private function exclusive(callable $callback): mixed
    {
        if (!(int) $this->query("SELECT GET_LOCK('synapse:collector', 0)")->fetchColumn()) {
            throw new HttpException('Another collection request is being processed.', 409);
        }
        try {
            $this->expireStale();
            return Database::transaction($callback);
        } finally {
            $this->query("SELECT RELEASE_LOCK('synapse:collector')");
        }
    }

    private function reserve(string $key, ?int $userId, ?int $sourceId): int
    {
        if (
            $this->one(
                "SELECT id FROM collector_runs WHERE status IN ('queued', 'running', 'dispatch_uncertain') LIMIT 1 FOR UPDATE",
            )
        ) {
            throw new HttpException('A collection run is already queued or running.', 409);
        }
        $dailyLimit = max(1, min(20, (int) Env::get('COLLECTOR_MAX_DAILY_RUNS', '4')));
        if (
            (int) $this->query(
                'SELECT COUNT(*) FROM collector_runs WHERE created_at >= CURDATE()',
            )->fetchColumn() >= $dailyLimit
        ) {
            throw new HttpException('The daily collection limit has been reached.', 429);
        }
        if (
            $this->one(
                'SELECT id FROM collector_runs WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1',
            )
        ) {
            throw new HttpException('Wait five minutes between collection runs.', 429);
        }
        $sources = array_filter(
            $this->sources(true),
            static fn(array $source): bool => $sourceId === null ||
                (int) $source['id'] === $sourceId,
        );
        if ($sources === []) {
            throw new HttpException('Enable at least one source with collection permission first.');
        }
        $this->query(
            'INSERT INTO collector_runs (run_key, requested_by, source_id) VALUES (?, ?, ?)',
            [$key, $userId, $sourceId],
        );
        return (int) $this->db->lastInsertId();
    }

    public function request(int $userId, ?int $sourceId): int
    {
        if ($sourceId !== null) {
            $this->source($sourceId);
        }
        return $this->exclusive(
            fn(): int => $this->reserve('manual:' . bin2hex(random_bytes(24)), $userId, $sourceId),
        );
    }

    public function claim(?int $id, string $execution): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,150}$/D', $execution)) {
            throw new HttpException('Invalid execution identity.');
        }
        return $this->exclusive(function () use ($id, $execution): array {
            if ($id === null) {
                $key = 'scheduled:' . date('Y-m-d');
                $existing = $this->one('SELECT id FROM collector_runs WHERE run_key = ?', [$key]);
                $id = $existing ? (int) $existing['id'] : $this->reserve($key, null, null);
            }
            $run = $this->run($id, true);
            if (in_array($run['status'], ['completed', 'partial', 'failed'], true)) {
                return ['skip' => true, 'run_id' => $id];
            }
            if ($run['status'] === 'running' && $run['execution_name'] !== $execution) {
                throw new HttpException('This run has already been claimed.', 409);
            }
            $sources = $run['sources_json']
                ? json_decode($run['sources_json'], true, 64, JSON_THROW_ON_ERROR)
                : null;
            if ($sources === null) {
                $sources = [];
                foreach ($this->sources(true) as $source) {
                    if (
                        $run['source_id'] !== null &&
                        (int) $source['id'] !== (int) $run['source_id']
                    ) {
                        continue;
                    }
                    $sources[] = [
                        'id' => (int) $source['id'],
                        'source_key' => $source['source_key'],
                        'name' => $source['name'],
                        'adapter' => $source['adapter'],
                        'url' => $source['url'],
                        'max_jobs' => (int) $source['max_jobs'],
                        'config' => json_decode(
                            $source['config_json'],
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        ),
                    ];
                }
                $this->query(
                    "UPDATE collector_runs SET status = 'running', execution_name = ?, sources_json = ?, started_at = NOW() WHERE id = ?",
                    [$execution, json_encode($sources, JSON_THROW_ON_ERROR), $id],
                );
            }
            return [
                'skip' => false,
                'run_id' => $id,
                'run_token' => $this->token($id, $execution),
                'sources' => $sources,
            ];
        });
    }

    private function token(int $id, string $execution): string
    {
        return hash_hmac('sha256', 'run:' . $id . ':' . $execution, Env::get('COLLECTOR_TOKEN'));
    }

    private function authenticatedRun(int $id, string $token): array
    {
        $run = $this->run($id, true);
        if (
            !$run['execution_name'] ||
            !hash_equals($this->token($id, $run['execution_name']), $token)
        ) {
            throw new HttpException('Invalid run credential.', 403);
        }
        return $run;
    }

    public function batch(int $id, string $token, int $sourceId, string $key, array $jobs): array
    {
        if (
            !preg_match('/^[a-z0-9-]{1,64}$/D', $key) ||
            !array_is_list($jobs) ||
            count($jobs) > 20 ||
            $jobs === []
        ) {
            throw new HttpException(
                'A batch must contain between one and twenty vacancies and a valid identity.',
            );
        }
        return Database::transaction(function () use ($id, $token, $sourceId, $key, $jobs): array {
            $run = $this->authenticatedRun($id, $token);
            $hash = hash('sha256', json_encode($jobs, JSON_THROW_ON_ERROR));
            $previous = $this->one(
                'SELECT * FROM collector_batches WHERE run_id = ? AND source_id = ? AND batch_key = ?',
                [$id, $sourceId, $key],
            );
            if ($previous) {
                if (!hash_equals($previous['payload_hash'], $hash)) {
                    throw new HttpException(
                        'This batch identity was already used for different content.',
                        409,
                    );
                }
                return [
                    'inserted' => (int) $previous['inserted_count'],
                    'updated' => (int) $previous['updated_count'],
                    'replayed' => true,
                ];
            }
            if ($run['status'] !== 'running' || strtotime($run['created_at']) < time() - 1800) {
                throw new HttpException('This collection run is no longer accepting results.', 409);
            }
            $sources = json_decode($run['sources_json'], true, 64, JSON_THROW_ON_ERROR);
            $source =
                array_values(
                    array_filter(
                        $sources,
                        static fn(array $source): bool => $source['id'] === $sourceId,
                    ),
                )[0] ?? null;
            if (!$source) {
                throw new HttpException('This source is not part of the collection run.', 403);
            }
            $processed = (int) $this->query(
                'SELECT COALESCE(SUM(record_count), 0) FROM collector_batches WHERE run_id = ? AND source_id = ?',
                [$id, $sourceId],
            )->fetchColumn();
            if ($processed + count($jobs) > $source['max_jobs']) {
                throw new HttpException('This source has exhausted its batch allowance.', 429);
            }
            $inserted = $updated = 0;
            foreach ($jobs as $input) {
                if (!is_array($input) || array_is_list($input)) {
                    throw new HttpException('Invalid vacancy object.');
                }
                $job = (new JobService())->normalise($input);
                if (!$job['original_url']) {
                    throw new HttpException('Collected vacancies require an original source URL.');
                }
                if ($job['deadline'] !== null && $job['deadline'] < date('Y-m-d')) {
                    continue;
                }
                $result = (new CatalogueRepository())->upsert($job, $sourceId);
                $inserted += $result['inserted'];
                $updated += $result['updated'];
            }
            $this->query(
                'INSERT INTO collector_batches (run_id, source_id, batch_key, payload_hash, record_count, inserted_count, updated_count) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $sourceId, $key, $hash, count($jobs), $inserted, $updated],
            );
            $this->query(
                'UPDATE collector_runs SET inserted_count = inserted_count + ?, updated_count = updated_count + ? WHERE id = ?',
                [$inserted, $updated, $id],
            );
            return ['inserted' => $inserted, 'updated' => $updated, 'replayed' => false];
        });
    }

    public function finish(int $id, string $token, array $results): array
    {
        return Database::transaction(function () use ($id, $token, $results): array {
            $run = $this->authenticatedRun($id, $token);
            if (in_array($run['status'], ['completed', 'partial', 'failed'], true)) {
                return ['status' => $run['status']];
            }
            $sources = json_decode($run['sources_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!array_is_list($results) || count($results) !== count($sources)) {
                throw new HttpException('Report a result for every source in the run.');
            }
            $indexed = [];
            foreach ($results as $result) {
                if (
                    !is_array($result) ||
                    !is_int($result['source_id'] ?? null) ||
                    isset($indexed[$result['source_id']]) ||
                    !in_array($result['status'] ?? '', ['completed', 'failed'], true) ||
                    !is_string($result['error'] ?? '')
                ) {
                    throw new HttpException('Invalid source result.');
                }
                $indexed[$result['source_id']] = $result;
            }
            $errors = [];
            foreach ($sources as $source) {
                $result =
                    $indexed[$source['id']] ?? throw new HttpException('Missing source result.');
                $ok = $result['status'] === 'completed';
                $error = $ok
                    ? null
                    : mb_substr(
                        preg_replace(
                            '/[\x00-\x1f\x7f]/',
                            ' ',
                            $result['error'] ?: 'Collection failed.',
                        ),
                        0,
                        1000,
                    );
                $this->query(
                    'UPDATE collector_sources SET health = ?, last_checked_at = NOW(), last_success_at = CASE WHEN ? = 1 THEN NOW() ELSE last_success_at END, last_error = ? WHERE id = ?',
                    [$ok ? 'connected' : 'error', (int) $ok, $error, $source['id']],
                );
                if (!$ok) {
                    $errors[] = $source['name'] . ': ' . $error;
                }
            }
            $status =
                $errors === []
                    ? 'completed'
                    : (count($errors) === count($sources)
                        ? 'failed'
                        : 'partial');
            $this->query(
                'UPDATE collector_runs SET status = ?, completed_at = NOW(), results_json = ?, error_summary = ? WHERE id = ?',
                [
                    $status,
                    json_encode($results, JSON_THROW_ON_ERROR),
                    $errors ? mb_substr(implode(' ', $errors), 0, 1000) : null,
                    $id,
                ],
            );
            return ['status' => $status];
        });
    }
}
