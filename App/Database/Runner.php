<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/** The existing SQL history, shared by CLI and authenticated deployment only. */
final class Runner
{
    public readonly PDO $db;
    private const INTERNAL = [
        'migrations',
        'deployment_migration_files',
        'deployment_migration_steps',
    ];

    public function __construct(private readonly string $root, private readonly array $config)
    {
        $address =
            $config['socket'] !== ''
                ? 'unix_socket=' . $config['socket']
                : 'host=' . $config['host'] . ';port=' . $config['port'];
        $this->db = new PDO(
            'mysql:' . $address . ';dbname=' . $config['name'] . ';charset=utf8mb4',
            $config['user'],
            $config['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ],
        );
        $this->db->exec("SET time_zone = '+03:00'");
    }

    public static function files(string $root): array
    {
        $files = [];
        $versions = [];
        foreach (glob($root . '/database/migrations/*.sql') as $path) {
            $name = basename($path);
            if (
                !preg_match('/^(\d{3,14})_[a-z0-9_]+\.sql$/D', $name, $match) ||
                isset($versions[$match[1]])
            ) {
                throw new RuntimeException('Invalid or duplicate SQL migration number.');
            }
            $versions[$match[1]] = true;
            $files[$name] = $path;
        }
        ksort($files, SORT_NATURAL);
        if (!isset($files['001_core.sql'])) {
            throw new RuntimeException('The initial schema migration is missing.');
        }
        return $files;
    }

    public function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function isEmpty(): bool
    {
        $tables = $this->db
            ->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_diff($tables, self::INTERNAL) === [];
    }

    public function hasUsers(): bool
    {
        return $this->tableExists('users') &&
            (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    /** Read-only. Never creates tables or adopts an unrecognised database. */
    public function history(): array
    {
        $files = self::files($this->root);
        $hasHistory = $this->tableExists('migrations');
        if (!$hasHistory && !$this->isEmpty()) {
            throw new RuntimeException(
                'Existing tables without Synapse migration history require manual review.',
            );
        }
        $applied = $hasHistory
            ? $this->db
                ->query('SELECT name FROM migrations ORDER BY name')
                ->fetchAll(PDO::FETCH_COLUMN)
            : [];
        if (array_diff($applied, array_keys($files))) {
            throw new RuntimeException('Recorded migrations are missing from this release.');
        }
        $pending = array_values(array_diff(array_keys($files), $applied));
        $positions = array_flip(array_keys($files));
        if (
            $pending &&
            $applied &&
            $positions[$pending[0]] < max(array_map(fn($name) => $positions[$name], $applied))
        ) {
            throw new RuntimeException('Out-of-order migration. Append a new numbered file.');
        }
        if ($this->tableExists('deployment_migration_files')) {
            foreach (
                $this->db->query('SELECT name, sha256 FROM deployment_migration_files')
                as $row
            ) {
                if (
                    !isset($files[$row['name']]) ||
                    !hash_equals($row['sha256'], hash_file('sha256', $files[$row['name']]))
                ) {
                    throw new RuntimeException(
                        'A recorded or interrupted SQL migration was altered. Restore it and add a new migration.',
                    );
                }
            }
        }
        return ['applied' => $applied, 'pending' => $pending];
    }

    public function check(): void
    {
        Schema::check($this->db, require $this->root . '/database/schema.php');
    }

    public function checkBaseline(array $history): void
    {
        if (!$history['pending']) {
            $this->check();
        }
    }

    public function locked(callable $operation): mixed
    {
        $name = 'synapse-migrations-' . substr(hash('sha256', $this->config['name']), 0, 40);
        $query = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$name]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration process is running.', 409);
        }
        try {
            return $operation();
        } finally {
            $query = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$name]);
        }
    }

    private function metadata(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(250) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS deployment_migration_files (name VARCHAR(250) PRIMARY KEY, sha256 CHAR(64) NOT NULL, recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS deployment_migration_steps (migration VARCHAR(250) NOT NULL, statement_no INT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL, status VARCHAR(10) NOT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL, PRIMARY KEY(migration,statement_no)) ENGINE=InnoDB',
        );
    }

    /** Caller holds the DB lock. One SQL file per call, preserving legacy history. */
    public function next(bool $retryFailed = false): array
    {
        $history = $this->history();
        $this->metadata();
        $files = self::files($this->root);
        $record = $this->db->prepare(
            'INSERT IGNORE INTO deployment_migration_files (name,sha256) VALUES (?,?)',
        );
        foreach ($history['applied'] as $name) {
            $record->execute([$name, hash_file('sha256', $files[$name])]);
        }
        if ($history['pending']) {
            $name = $history['pending'][0];
            $record->execute([$name, hash_file('sha256', $files[$name])]);
            foreach (Sql::split(file_get_contents($files[$name])) as $index => $sql) {
                $query = $this->db->prepare(
                    'SELECT sha256,status FROM deployment_migration_steps WHERE migration=? AND statement_no=?',
                );
                $query->execute([$name, $index]);
                $step = $query->fetch();
                $hash = hash('sha256', $sql);
                if ($step && !hash_equals($step['sha256'], $hash)) {
                    throw new RuntimeException('An interrupted SQL statement changed.');
                }
                if (($step['status'] ?? '') === 'done') {
                    continue;
                }
                $resuming = $step !== false;
                if ($resuming && !$retryFailed) {
                    throw new RuntimeException(
                        'Interrupted migration requires review and an explicit retry-failed option.',
                    );
                }
                if (!$step) {
                    $query = $this->db->prepare(
                        "INSERT INTO deployment_migration_steps (migration,statement_no,sha256,status) VALUES (?,?,?,'running')",
                    );
                    $query->execute([$name, $index, $hash]);
                }
                $sql = $resuming ? $this->resumeDdl($sql) : $sql;
                if ($sql !== null) {
                    $this->db->exec($sql);
                }
                $query = $this->db->prepare(
                    "UPDATE deployment_migration_steps SET status='done',completed_at=NOW() WHERE migration=? AND statement_no=?",
                );
                $query->execute([$name, $index]);
            }
            $query = $this->db->prepare('INSERT INTO migrations (name) VALUES (?)');
            $query->execute([$name]);
            $history = $this->history();
        }
        if (!$history['pending']) {
            $this->check();
        }
        return $history;
    }

    /** Reconcile only known additive DDL after an interrupted statement. */
    private function resumeDdl(string $sql): ?string
    {
        if (
            preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `?([a-zA-Z0-9_]+)`?\s*\(/i', $sql, $match)
        ) {
            return $this->tableExists($match[1]) ? null : $sql;
        }
        if (!preg_match('/^ALTER TABLE `?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $sql, $match)) {
            throw new RuntimeException(
                'An interrupted data or non-additive statement requires manual reconciliation before retrying.',
            );
        }
        $table = $match[1];
        $remaining = [];
        foreach (Sql::split($match[2], ',') as $clause) {
            if (
                preg_match('/^ADD\s+(?:COLUMN\s+)?`?([a-zA-Z0-9_]+)`?\s+/i', $clause, $column) &&
                !in_array(
                    strtoupper($column[1]),
                    ['UNIQUE', 'INDEX', 'KEY', 'CONSTRAINT', 'PRIMARY', 'FOREIGN'],
                    true,
                )
            ) {
                $query = $this->db->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
                );
                $query->execute([$table, $column[1]]);
            } elseif (
                preg_match(
                    '/^ADD\s+(?:UNIQUE\s+(?:KEY|INDEX)?\s*|INDEX\s+|KEY\s+)`?([a-zA-Z0-9_]+)`?\s*\(/i',
                    $clause,
                    $index,
                )
            ) {
                $query = $this->db->prepare(
                    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
                );
                $query->execute([$table, $index[1]]);
            } else {
                throw new RuntimeException(
                    'This interrupted ALTER requires manual reconciliation.',
                );
            }
            if ((int) $query->fetchColumn() === 0) {
                $remaining[] = $clause;
            }
        }
        return $remaining ? 'ALTER TABLE `' . $table . '` ' . implode(', ', $remaining) : null;
    }
}
