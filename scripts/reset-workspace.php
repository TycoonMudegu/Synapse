<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;
use App\Core\Env;
use App\Repositories\AccountRepository;

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--confirm=' . Env::get('DB_DATABASE', 'jobflow')) {
    fwrite(
        STDERR,
        "This removes every user and their workspace data. Pass --confirm=<configured-database-name> to run. A private backup is created first.\n",
    );
    exit(1);
}
$db = Database::connection();
$locks = ['jobflow:scheduler'];
$types = [
    'discovery',
    'analyse_job',
    'prepare_application',
    'submit_application',
    'manual_job_alert',
    'gmail_sync',
    'verify_deadlines',
];
foreach ($db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    foreach ($types as $type) {
        $locks[] = 'jobflow:' . $id . ':' . $type;
    }
}
$held = [];
try {
    foreach ($locks as $lock) {
        $statement = $db->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'A worker is busy. Retry when it has finished. No data has been removed.',
            );
        }
        $held[] = $lock;
    }
    $directory =
        BASE_PATH . '/storage/run/backups/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($directory, 0700, true)) {
        throw new RuntimeException('Could not create private backup directory.');
    }
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    // Snapshot and deletion share one database transaction. Foreign keys stay enabled.
    Database::transaction(function () use ($db, $tables, $directory): void {
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new RuntimeException('Unexpected database table name.');
            }
            $rows = $db->query('SELECT * FROM `' . $table . '`')->fetchAll();
            if (
                file_put_contents(
                    $directory . '/' . $table . '.json',
                    json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    LOCK_EX,
                ) === false
            ) {
                throw new RuntimeException('The private backup could not be written.');
            }
        }
        $accounts = new AccountRepository();
        $sources = $accounts->site('job_sources');
        foreach (
            $db
                ->query(
                    "SELECT preset_key, MAX(health = 'connected' AND enabled = 1) AS connected FROM job_sources WHERE preset_key IS NOT NULL GROUP BY preset_key",
                )
                ->fetchAll()
            as $source
        ) {
            if ($source['connected']) {
                $sources[$source['preset_key']] = ['enabled' => true, 'health' => 'connected'];
            }
        }
        $accounts->saveSite('job_sources', $sources);
        if (!$accounts->site('ai_provider')) {
            $provider = $db
                ->query(
                    "SELECT name, endpoint, encrypted_key, model, options_json FROM ai_providers WHERE health = 'connected' ORDER BY id LIMIT 1",
                )
                ->fetch();
            if ($provider) {
                $accounts->saveSite('ai_provider', $provider);
            }
        }
        // Existing attachment FKs restrict user deletion until applications are gone.
        $db->exec('DELETE FROM applications');
        $db->exec('DELETE FROM users');
        $db->exec('DELETE FROM login_attempts');
        $db->exec('DELETE FROM request_limits');
    });
    // Originals move into the same private backup; no user file is left accessible.
    mkdir($directory . '/documents', 0700);
    foreach (glob(BASE_PATH . '/storage/private/*') as $path) {
        if (is_file($path) && !rename($path, $directory . '/documents/' . basename($path))) {
            fwrite(
                STDERR,
                "A private original could not be archived; it remains outside public access.\n",
            );
        }
    }
    foreach (glob(BASE_PATH . '/storage/sessions/sess_*') as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    echo 'Workspace reset. All users, jobs and user settings removed. Site configuration preserved.' .
        PHP_EOL;
    echo 'Private backup: ' . $directory . PHP_EOL;
} finally {
    foreach (array_reverse($held) as $lock) {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}
