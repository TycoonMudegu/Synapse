<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;
use Throwable;

/** Authenticated deployment operation; never called by an ordinary app request. */
final class HttpDeployment
{
    public static function handle(string $root, string $envFile, array $server, string $body): array
    {
        $packageVerified = false;
        try {
            if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
                return self::response(405, ['error' => 'POST required.']);
            }
            if (($server['HTTPS'] ?? '') !== 'on' && (int) ($server['SERVER_PORT'] ?? 0) !== 443) {
                return self::response(400, ['error' => 'HTTPS required.']);
            }
            // Check authentication before loading uploaded dependencies or opening the DB.
            $environment = @file_get_contents($envFile);
            if (
                $environment === false ||
                !preg_match(
                    '/^DB_DEPLOY_SECRET\s*=\s*"?([a-f0-9]{64})"?\s*$/m',
                    $environment,
                    $match,
                )
            ) {
                return self::response(503, ['error' => 'Deployment endpoint is not configured.']);
            }
            $timestamp = (string) ($server['HTTP_X_DEPLOY_TIMESTAMP'] ?? '');
            $signature = (string) ($server['HTTP_X_DEPLOY_SIGNATURE'] ?? '');
            if (
                strlen($body) > 4096 ||
                !ctype_digit($timestamp) ||
                abs(time() - (int) $timestamp) > 300 ||
                !hash_equals(hash_hmac('sha256', $timestamp . "\n" . $body, $match[1]), $signature)
            ) {
                return self::response(403, ['error' => 'Invalid deployment authentication.']);
            }
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($request) ||
                !in_array($request['action'] ?? '', ['status', 'step'], true) ||
                !preg_match('/^[a-f0-9]{40}$/', $request['release'] ?? '') ||
                !preg_match('/^[a-f0-9]{64}$/', $request['package'] ?? '') ||
                !is_bool($request['retry_failed'] ?? false) ||
                !is_bool($request['backup_window_override'] ?? false) ||
                !in_array(
                    $request['backup_mode'] ?? 'hosting-daily',
                    ['hosting-daily', 'deployment-snapshot'],
                    true,
                )
            ) {
                return self::response(400, ['error' => 'Invalid deployment request.']);
            }
            if ($request['backup_window_override'] ?? false) {
                $reason = $request['backup_window_override_reason'] ?? null;
                if (
                    !is_string($reason) ||
                    strlen(trim($reason)) < 10 ||
                    strlen($reason) > 500 ||
                    preg_match('/[\x00-\x1F\x7F]/', $reason)
                ) {
                    return self::response(400, [
                        'error' =>
                            'An emergency backup-window override requires a reason of 10 to 500 characters on one line.',
                    ]);
                }
            }
            self::verifyPackage($root, $request['release'], $request['package']);
            $packageVerified = true;
            // Hosting's PHP log may point at an unavailable development path.
            // This shared directory has verified access denial and survives releases.
            ini_set('log_errors', '1');
            ini_set('error_log', dirname($root, 2) . '/deployment-error.log');
            require_once $root . '/vendor/autoload.php';
            $env = \App\Core\Env::parse($environment);
            $connection = require $root . '/database/connection.php';
            $config = $connection($envFile);
            $runner = new Runner($root, $config);
            return $runner->locked(static function () use ($runner, $root, $request, $env): array {
                $history = $runner->history();
                $runner->checkBaseline($history);
                // Shared by every release so a new upload cannot bypass a failed attempt.
                $stateFile = dirname($root, 2) . '/deployment-state.json';
                $state = is_file($stateFile)
                    ? json_decode(file_get_contents($stateFile), true, 32, JSON_THROW_ON_ERROR)
                    : [];
                $attempt = $state['attempt'] ?? null;
                // A lost response is harmless if the migration ledger already recorded it.
                $interrupted = $attempt !== null && !in_array($attempt, $history['applied'], true);
                if ($interrupted && !in_array($attempt, $history['pending'], true)) {
                    return self::response(409, [
                        'error' => 'An interrupted migration from another release requires review.',
                        'status' => 'needs_review',
                    ]);
                }
                $initial =
                    (($state['release'] ?? '') === $request['release'] &&
                        !empty($state['initial_install'])) ||
                    ($history['applied'] === [] && $runner->isEmpty());
                // A new, unpublished empty database has no user data to back up.
                // Once any account exists, normal backup requirements apply.
                $initial = $initial && !$runner->hasUsers();
                $snapshotMode = ($request['backup_mode'] ?? '') === 'deployment-snapshot';
                $backup = $initial
                    ? [
                        'open' => true,
                        'reason' => 'initial_install',
                        'message' => 'Initial empty-database installation.',
                        'provider_completion_verified' => false,
                    ]
                    : ($snapshotMode
                        ? [
                            'open' => true,
                            'reason' => 'deployment_snapshot',
                            'message' =>
                                'A private database snapshot must complete before migration starts.',
                            'provider_completion_verified' => false,
                        ]
                        : self::backupWindow($env));
                // Only an explicitly signed request can bypass the schedule.
                // Invalid hosting configuration and failed-migration review
                // remain separate gates. Provider backup success is not assumed.
                $override =
                    ($request['backup_window_override'] ?? false) &&
                    $backup['reason'] === 'outside_window';
                if (!$history['pending']) {
                    $runner->check();
                    return self::response(
                        200,
                        ['status' => 'complete', 'release' => $request['release']] + $history,
                    );
                }
                if ($request['action'] === 'status') {
                    $diagnostics = $interrupted
                        ? [
                            'schema_missing' => Schema::missing(
                                $runner->db,
                                require $root . '/database/schema.php',
                            ),
                        ]
                        : [];
                    if ($interrupted && is_file(dirname($root, 2) . '/deployment-error.log')) {
                        $diagnostics['error_log'] = '_db-deploy/deployment-error.log';
                    }
                    return self::response(
                        200,
                        [
                            'status' => $interrupted ? 'needs_review' : 'pending',
                            'release' => $request['release'],
                            'backup_window_open' => $backup['open'],
                            'backup_window_override_active' => $override,
                            'backup_window' => $backup,
                        ] +
                            $history +
                            $diagnostics,
                    );
                }
                if ($interrupted && !($request['retry_failed'] ?? false)) {
                    return self::response(409, [
                        'error' => 'Interrupted migration requires review before retry.',
                        'status' => 'needs_review',
                    ]);
                }
                if (!$backup['open'] && !$override) {
                    return self::response(412, [
                        'error' => 'Migration blocked: ' . $backup['message'],
                        'backup_window' => $backup,
                    ]);
                }
                $snapshot =
                    $snapshotMode && !$initial
                        ? Snapshot::create(
                            $runner->db,
                            dirname($root, 2) . '/backups',
                            $request['release'],
                        )
                        : null;
                $state = [
                    'release' => $request['release'],
                    'attempt' => $history['pending'][0],
                    'started_at' => gmdate('c'),
                    'backup_policy' => $backup,
                    'initial_install' => $initial,
                    'database_snapshot' => $snapshot,
                ];
                if ($override) {
                    $state['backup_window_override'] = [
                        'release' => $request['release'],
                        'reason' => trim($request['backup_window_override_reason']),
                        'authorized_at_utc' => gmdate('c'),
                    ];
                }
                // Persist before DDL: a timeout/fatal error leaves a reviewable attempt.
                self::saveState($stateFile, $state);
                if (
                    $override &&
                    file_put_contents(
                        dirname($root, 2) . '/backup-window-overrides.jsonl',
                        json_encode(
                            $state['backup_window_override'] + ['migration' => $state['attempt']],
                            JSON_THROW_ON_ERROR,
                        ) . PHP_EOL,
                        FILE_APPEND | LOCK_EX,
                    ) === false
                ) {
                    throw new RuntimeException(
                        'Cannot record the emergency backup-window override.',
                    );
                }
                $history = $runner->next($request['retry_failed'] ?? false);
                $state['attempt'] = null;
                $state['completed_at'] = gmdate('c');
                self::saveState($stateFile, $state);
                return self::response(
                    200,
                    [
                        'status' => $history['pending'] ? 'pending' : 'complete',
                        'release' => $request['release'],
                    ] + $history,
                );
            });
        } catch (Throwable $error) {
            // Never send raw SQL, filesystem paths, credentials or business data to CI.
            $message = 'Database deployment failed: ' . $error->getMessage();
            $logged =
                $packageVerified &&
                @error_log(
                    '[' . gmdate('c') . '] ' . $message . PHP_EOL,
                    3,
                    dirname($root, 2) . '/deployment-error.log',
                );
            if (!$logged) {
                error_log($message);
            }
            $diagnostics = [];
            if ($packageVerified) {
                if ($logged) {
                    $diagnostics['error_log'] = '_db-deploy/deployment-error.log';
                }
                if ($error instanceof \PDOException && is_array($error->errorInfo)) {
                    $sqlState = (string) ($error->errorInfo[0] ?? '');
                    if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) {
                        $diagnostics['sqlstate'] = $sqlState;
                    }
                    $driverCode = $error->errorInfo[1] ?? null;
                    if (is_int($driverCode)) {
                        $diagnostics['database_error_code'] = $driverCode;
                    }
                }
                if (isset($runner)) {
                    try {
                        $diagnostics['schema_missing'] = Schema::missing(
                            $runner->db,
                            require $root . '/database/schema.php',
                        );
                    } catch (Throwable) {
                        /* A failed DB connection must retain its original error. */
                    }
                }
            }
            return self::response(
                $error->getCode() === 409 ? 409 : 500,
                [
                    'error' =>
                        $error->getCode() === 409
                            ? 'Another migration process is running.'
                            : ($packageVerified
                                ? 'Deployment failed.'
                                : 'Deployment failed. Review the hosting PHP error log before retrying.'),
                ] + $diagnostics,
            );
        }
    }

    private static function response(int $code, array $body): array
    {
        return ['code' => $code, 'body' => $body];
    }

    private static function verifyPackage(string $root, string $release, string $digest): void
    {
        $raw = @file_get_contents($root . '/manifest.json');
        if ($raw === false || !hash_equals($digest, hash('sha256', $raw))) {
            throw new RuntimeException('Migration package manifest mismatch or incomplete upload.');
        }
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['release'] ?? '') !== $release || empty($manifest['files'])) {
            throw new RuntimeException('Migration release does not match request.');
        }
        $rules = @file_get_contents(dirname($root, 2) . '/.htaccess');
        if (
            $rules === false ||
            !hash_equals($manifest['access_rules'] ?? '', hash('sha256', $rules))
        ) {
            throw new RuntimeException('Migration access protection was not uploaded.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            if (
                !preg_match('~^[a-zA-Z0-9_.@\-/]+$~', $path) ||
                str_starts_with($path, '/') ||
                in_array('..', explode('/', $path), true) ||
                !is_file($root . '/' . $path) ||
                !hash_equals($hash, hash_file('sha256', $root . '/' . $path))
            ) {
                throw new RuntimeException('Migration package is incomplete or altered: ' . $path);
            }
        }
        // Reject SQL files not included in this authenticated package.
        foreach (glob($root . '/database/migrations/*.sql') as $path) {
            if (!isset($manifest['files']['database/migrations/' . basename($path)])) {
                throw new RuntimeException('Unmanifested migration file.');
            }
        }
    }

    public static function backupWindow(array $env, ?int $now = null): array
    {
        // This is an operator-selected schedule, NOT evidence of provider success.
        $now ??= time();
        $result = [
            'open' => false,
            'evaluated_at_utc' => gmdate('c', $now),
            'provider_completion_verified' => false,
        ];
        if (($env['DB_DEPLOY_BACKUP_POLICY'] ?? '') !== 'hosting-daily') {
            return $result + [
                'reason' => 'invalid_policy',
                'message' =>
                    'Set DB_DEPLOY_BACKUP_POLICY=hosting-daily in hosting .env to use the hosting backup schedule.',
            ];
        }
        $time = $env['DB_DEPLOY_BACKUP_UTC'] ?? '';
        $hours = filter_var($env['DB_DEPLOY_BACKUP_MAX_AGE_HOURS'] ?? '4', FILTER_VALIDATE_INT);
        if (
            !preg_match('/^(\d{2}):(\d{2})$/', $time, $parts) ||
            (int) $parts[1] > 23 ||
            (int) $parts[2] > 59
        ) {
            return $result + [
                'reason' => 'invalid_schedule',
                'message' =>
                    'Set DB_DEPLOY_BACKUP_UTC=HH:MM in hosting .env using the actual backup time converted to UTC.',
            ];
        }
        if ($hours < 1 || $hours > 12) {
            return $result + [
                'reason' => 'invalid_max_age',
                'message' =>
                    'Set DB_DEPLOY_BACKUP_MAX_AGE_HOURS to an integer from 1 to 12 in hosting .env.',
            ];
        }
        // Build the UTC day explicitly, independent of hosting's PHP timezone.
        $scheduled = $now - ($now % 86400) + (int) $parts[1] * 3600 + (int) $parts[2] * 60;
        if ($scheduled > $now) {
            $scheduled -= 86400;
        }
        $result['open'] = $now - $scheduled <= $hours * 3600;
        $result += [
            'policy' => 'hosting-daily',
            'configured_time_utc' => $time,
            'max_age_hours' => $hours,
            'scheduled_at' => gmdate('c', $scheduled),
            'closes_at_utc' => gmdate('c', $scheduled + $hours * 3600),
            'next_opens_at_utc' => gmdate('c', $scheduled + 86400),
            'next_closes_at_utc' => gmdate('c', $scheduled + 86400 + $hours * 3600),
        ];
        return $result +
            ($result['open']
                ? ['reason' => 'open', 'message' => 'Configured hosting backup window is open.']
                : [
                    'reason' => 'outside_window',
                    'message' =>
                        'Backup window is closed. Hosting .env expects backups at ' .
                        $time .
                        ' UTC and allows migrations for ' .
                        $hours .
                        ' hours afterward. Server time: ' .
                        $result['evaluated_at_utc'] .
                        '. Next allowed window: ' .
                        $result['next_opens_at_utc'] .
                        ' to ' .
                        $result['next_closes_at_utc'] .
                        '. Check DB_DEPLOY_BACKUP_UTC against the backup dashboard timezone.',
                ]);
    }

    private static function saveState(string $file, array $state): void
    {
        $temporary = $file . '.tmp';
        if (
            file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) ===
                false ||
            !rename($temporary, $file)
        ) {
            throw new RuntimeException('Cannot persist deployment progress.');
        }
    }
}
