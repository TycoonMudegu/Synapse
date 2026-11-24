<?php

declare(strict_types=1);

// Disposable databases only; never loads the installation's .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\HttpDeployment;
use App\Database\Runner;
use App\Database\Sql;

$root = dirname(__DIR__);
$socket = getenv('DB_SOCKET') ?: '';
$config = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'user' => getenv('DB_USERNAME') ?: 'root',
    'pass' => getenv('DB_PASSWORD') ?: '',
    'socket' => $socket,
];
$address =
    $socket !== ''
        ? 'unix_socket=' . $socket
        : 'host=' . $config['host'] . ';port=' . $config['port'];
$server = new PDO('mysql:' . $address . ';charset=utf8mb4', $config['user'], $config['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$name = 'synapse_migration_' . bin2hex(random_bytes(5)) . '_test';
$httpName = 'synapse_http_' . bin2hex(random_bytes(5)) . '_test';
$temporary = sys_get_temp_dir() . '/synapse-migrations-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$passed = 0;
function ensure(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
}
function denied(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        ensure(
            str_contains($error->getMessage(), $message),
            'Unexpected rejection: ' . $error->getMessage(),
        );
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function copy_tree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0700, true);
    }
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $destination = $to . '/' . $entry->getFilename();
        $entry->isDir()
            ? copy_tree($entry->getPathname(), $destination)
            : copy($entry->getPathname(), $destination);
    }
}
function remove_tree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            remove_tree($entry->getPathname());
        }
    }
    rmdir($path);
}
function sql_value(PDO $db, string $sql): mixed
{
    return $db->query($sql)->fetchColumn();
}

try {
    foreach ([$name, $httpName] as $database) {
        ensure(
            (bool) preg_match('/^synapse_[a-z0-9_]+_test$/D', $database),
            'Test database name rejected.',
        );
        $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
    }
    copy_tree($root . '/database', $temporary . '/database');
    $config['name'] = $name;
    $runner = new Runner($temporary, $config);
    ensure(
        $runner->history()['applied'] === [] && count($runner->history()['pending']) === 11,
        'Fresh history incorrect.',
    );
    ensure(
        (int) sql_value(
            $runner->db,
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
        ) === 0,
        'Status modified the database.',
    );
    $runner->locked(function () use ($runner): void {
        do {
            $history = $runner->next();
        } while ($history['pending']);
    });
    ensure(count($runner->history()['applied']) === 11, 'Clean SQL installation incomplete.');
    $runner->check();
    ensure(
        (int) sql_value($runner->db, 'SELECT COUNT(*) FROM promo_codes') === 1,
        'Initial data did not run exactly once.',
    );
    $runner->db->exec(
        "INSERT INTO users (name,email,password_hash) VALUES ('Preserve this account','deployment@example.test','fixture')",
    );
    $runner->locked(fn() => $runner->next());
    ensure(
        sql_value($runner->db, 'SELECT name FROM users') === 'Preserve this account',
        'Repeat migration changed user data.',
    );
    ensure(
        (int) sql_value($runner->db, 'SELECT COUNT(*) FROM promo_codes') === 1,
        'Repeat migration replayed data.',
    );
    $runner->db->exec('DROP TABLE deployment_migration_steps');
    $runner->db->exec('DROP TABLE deployment_migration_files');
    $runner->locked(fn() => $runner->next());
    ensure(
        (int) sql_value($runner->db, 'SELECT COUNT(*) FROM deployment_migration_files') === 11,
        'Legacy history was not adopted.',
    );
    ensure(
        sql_value($runner->db, 'SELECT name FROM users') === 'Preserve this account',
        'Legacy adoption changed user data.',
    );

    $release = str_repeat('a', 40);
    $secret = str_repeat('b', 64);
    $package = $temporary . '/_db-deploy/releases/' . $release;
    mkdir($package . '/vendor', 0700, true);
    copy_tree($root . '/database', $package . '/database');
    copy_tree($root . '/App/Database', $package . '/App/Database');
    mkdir($package . '/App/Core', 0700, true);
    copy($root . '/App/Core/Env.php', $package . '/App/Core/Env.php');
    copy($root . '/database/runtime_autoload.php', $package . '/vendor/autoload.php');
    $rules =
        "Options -Indexes\nRequire all denied\n<Files \"endpoint.php\">\nRequire all granted\n</Files>\n";
    file_put_contents($temporary . '/_db-deploy/.htaccess', $rules);
    file_put_contents($package . '/.htaccess', $rules);
    $files = [];
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS),
        )
        as $file
    ) {
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($package) + 1)] = hash_file(
                'sha256',
                $file->getPathname(),
            );
        }
    }
    file_put_contents(
        $package . '/manifest.json',
        json_encode(
            ['release' => $release, 'files' => $files, 'access_rules' => hash('sha256', $rules)],
            JSON_THROW_ON_ERROR,
        ),
    );
    $packageHash = hash_file('sha256', $package . '/manifest.json');
    $httpConfig = $config;
    $httpConfig['name'] = $httpName;
    $environment = [
        'DB_DEPLOY_SECRET' => $secret,
        'DB_HOST' => $config['host'],
        'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => $httpName,
        'DB_USERNAME' => $config['user'],
        'DB_PASSWORD' => $config['pass'],
        'DB_SOCKET' => $socket,
    ];
    $envFile = $temporary . '/hosting.env';
    $contents = '';
    foreach ($environment as $key => $value) {
        $contents .= $key . '=' . $value . "\n";
    }
    file_put_contents($envFile, $contents);
    $request = function (string $action, array $extra = [], array $serverExtra = []) use (
        $package,
        $envFile,
        $secret,
        $release,
        $packageHash,
    ): array {
        $body = json_encode(
            ['action' => $action, 'release' => $release, 'package' => $packageHash] + $extra,
            JSON_THROW_ON_ERROR,
        );
        $timestamp = (string) time();
        return HttpDeployment::handle(
            $package,
            $envFile,
            $serverExtra + [
                'REQUEST_METHOD' => 'POST',
                'HTTPS' => 'on',
                'HTTP_X_DEPLOY_TIMESTAMP' => $timestamp,
                'HTTP_X_DEPLOY_SIGNATURE' => hash_hmac(
                    'sha256',
                    $timestamp . "\n" . $body,
                    $secret,
                ),
            ],
            $body,
        );
    };
    ensure(
        $request('status', [], ['HTTP_X_DEPLOY_SIGNATURE' => 'invalid'])['code'] === 403,
        'Unsigned request was accepted.',
    );
    ensure(
        $request('status', [], ['HTTP_X_DEPLOY_TIMESTAMP' => '1'])['code'] === 403,
        'Expired request was accepted.',
    );
    ensure(
        $request('status', [], ['REQUEST_METHOD' => 'GET'])['code'] === 405,
        'GET allowed a migration operation.',
    );
    ensure(
        $request('status', [], ['HTTPS' => 'off'])['code'] === 400,
        'HTTP migration was accepted.',
    );
    $status = $request('status');
    ensure(
        $status['code'] === 200 &&
            $status['body']['status'] === 'pending' &&
            $status['body']['backup_window_open'],
        'Initial installation status failed: ' . json_encode($status),
    );
    $httpRunner = new Runner($package, $httpConfig);
    ensure($httpRunner->isEmpty(), 'Authenticated status modified the database.');
    for ($i = 0; $i < 11; $i++) {
        $response = $request('step');
        ensure(
            $response['code'] === 200,
            'Authenticated fresh install failed: ' . json_encode($response),
        );
    }
    ensure(
        $request('status')['body']['status'] === 'complete' &&
            count($httpRunner->history()['applied']) === 11,
        'HTTP installation incomplete.',
    );
    file_put_contents($package . '/database/migrations/999_unmanifested.sql', 'SELECT 1;');
    ensure($request('status')['code'] === 500, 'Unmanifested SQL accepted.');
    unlink($package . '/database/migrations/999_unmanifested.sql');
    file_put_contents($package . '/database/schema.php', "\n// altered", FILE_APPEND);
    ensure($request('status')['code'] === 500, 'Modified package accepted.');

    $fixture =
        'ALTER TABLE users ADD deployment_fixture VARCHAR(30) NULL;' .
        "\n" .
        'ALTER TABLE users ADD deployment_fixture_two INT NULL;';
    $fixturePath = $temporary . '/database/migrations/012_retry_fixture.sql';
    file_put_contents($fixturePath, $fixture);
    $query = $runner->db->prepare(
        'INSERT INTO deployment_migration_files (name,sha256) VALUES (?,?)',
    );
    $query->execute(['012_retry_fixture.sql', hash('sha256', $fixture)]);
    $first = Sql::split($fixture)[0];
    $query = $runner->db->prepare(
        "INSERT INTO deployment_migration_steps (migration,statement_no,sha256,status) VALUES (?,0,?,'running')",
    );
    $query->execute(['012_retry_fixture.sql', hash('sha256', $first)]);
    $runner->db->exec($first);
    denied(fn() => $runner->locked(fn() => $runner->next()), 'explicit retry-failed');
    $runner->locked(fn() => $runner->next(true));
    ensure(
        in_array('012_retry_fixture.sql', $runner->history()['applied'], true),
        'Interrupted additive DDL did not resume.',
    );
    ensure(
        sql_value($runner->db, 'SELECT name FROM users') === 'Preserve this account',
        'Resumption changed user data.',
    );
    file_put_contents($fixturePath, $fixture . "\n-- changed");
    denied(fn() => $runner->history(), 'was altered');
    file_put_contents($fixturePath, $fixture);
    file_put_contents($temporary . '/database/migrations/000_out_of_order.sql', 'SELECT 1;');
    denied(fn() => $runner->history(), 'Out-of-order');
    unlink($temporary . '/database/migrations/000_out_of_order.sql');
    ensure(
        count(
            Sql::split("INSERT INTO t VALUES ('semi;colon'); -- ignored;\n SELECT 'it''s;quoted';"),
        ) === 2,
        'SQL quote/comment splitting failed.',
    );
    ensure(
        HttpDeployment::backupWindow(
            ['DB_DEPLOY_BACKUP_POLICY' => 'hosting-daily', 'DB_DEPLOY_BACKUP_UTC' => '12:00'],
            strtotime('2026-11-24 13:00 UTC'),
        )['open'],
        'UTC backup schedule wrong.',
    );
    ensure(
        !HttpDeployment::backupWindow(
            ['DB_DEPLOY_BACKUP_POLICY' => 'hosting-daily', 'DB_DEPLOY_BACKUP_UTC' => '12:00'],
            strtotime('2026-11-24 18:00 UTC'),
        )['open'],
        'Closed backup window accepted.',
    );
    echo $passed . ' deployment database checks passed.' . PHP_EOL;
} finally {
    foreach ([$name, $httpName] as $database) {
        $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
    remove_tree($temporary);
}
