<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$db = Database::connection();
$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(250) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
);
$lock = $db->query("SELECT GET_LOCK('jobflow:migrations', 10)")->fetchColumn();
if ((int) $lock !== 1) {
    throw new RuntimeException('Another migration process is running.');
}
try {
    foreach (glob(BASE_PATH . '/database/migrations/*.sql') as $file) {
        $name = basename($file);
        $check = $db->prepare('SELECT name FROM migrations WHERE name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn()) {
            continue;
        }
        // MySQL DDL auto-commits. Restore a backup before retrying a partially failed migration.
        $db->exec(file_get_contents($file));
        $insert = $db->prepare('INSERT INTO migrations (name) VALUES (?)');
        $insert->execute([$name]);
        echo 'Applied ' . $name . PHP_EOL;
    }
    echo 'Database is up to date.' . PHP_EOL;
} finally {
    $db->query("SELECT RELEASE_LOCK('jobflow:migrations')");
}
