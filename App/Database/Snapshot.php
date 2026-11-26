<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/** A private, consistent SQL snapshot for Synapse's InnoDB schema before deployment DDL. */
final class Snapshot
{
    public static function create(PDO $db, string $directory, string $release): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $release) || is_link($directory)) {
            throw new RuntimeException('Invalid database snapshot destination.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create private database snapshot storage.');
        }
        if (!chmod($directory, 0700) || !is_writable($directory)) {
            throw new RuntimeException('Database snapshot storage is not private and writable.');
        }
        $rules = $directory . '/.htaccess';
        if (
            is_link($rules) ||
            (is_file($rules) && file_get_contents($rules) !== "Require all denied\n")
        ) {
            throw new RuntimeException('Database snapshot access protection changed.');
        }
        if (
            !is_file($rules) &&
            file_put_contents($rules, "Require all denied\n", LOCK_EX) === false
        ) {
            throw new RuntimeException('Cannot protect database snapshots from web access.');
        }
        $filename = 'before-' . $release . '.sql.gz';
        $destination = $directory . '/' . $filename;
        $manifest = $destination . '.json';
        if (file_exists($destination) || file_exists($manifest)) {
            $saved =
                is_file($manifest) && !is_link($manifest)
                    ? json_decode(file_get_contents($manifest), true)
                    : null;
            if (
                !is_array($saved) ||
                ($saved['release'] ?? '') !== $release ||
                !is_file($destination) ||
                is_link($destination) ||
                !hash_equals($saved['sha256'] ?? '', hash_file('sha256', $destination))
            ) {
                throw new RuntimeException(
                    'Existing database snapshot is incomplete or changed. Review it before retrying.',
                );
            }
            return $saved;
        }
        if (!function_exists('gzopen')) {
            throw new RuntimeException('Database snapshots require PHP zlib.');
        }
        $tables = $db
            ->query(
                'SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
            )
            ->fetchAll();
        foreach ($tables as $table) {
            if (
                $table['TABLE_TYPE'] !== 'BASE TABLE' ||
                strtoupper($table['ENGINE'] ?? '') !== 'INNODB'
            ) {
                throw new RuntimeException(
                    'Use a native database backup for schemas with views or non-InnoDB tables.',
                );
            }
        }
        foreach (
            [
                'TRIGGERS' => 'TRIGGER_SCHEMA',
                'ROUTINES' => 'ROUTINE_SCHEMA',
                'EVENTS' => 'EVENT_SCHEMA',
            ]
            as $type => $field
        ) {
            if (
                (int) $db
                    ->query(
                        'SELECT COUNT(*) FROM information_schema.' .
                            $type .
                            ' WHERE ' .
                            $field .
                            ' = DATABASE()',
                    )
                    ->fetchColumn() > 0
            ) {
                throw new RuntimeException(
                    'Use a native database backup for schemas with triggers, routines or events.',
                );
            }
        }
        $temporary = tempnam($directory, '.snapshot-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate a private database snapshot.');
        }
        chmod($temporary, 0600);
        $stream = null;
        $started = hrtime(true);
        $bytes = $rows = 0;
        $hash = hash_init('sha256');
        $write = static function (string $sql) use (&$stream, &$bytes, $hash, $started): void {
            $bytes += strlen($sql);
            if ($bytes > 134217728 || (hrtime(true) - $started) / 1e9 > 45) {
                throw new RuntimeException(
                    'Database snapshot exceeded its deployment size or time allowance; migration was not started.',
                );
            }
            if (gzwrite($stream, $sql) !== strlen($sql)) {
                throw new RuntimeException('Database snapshot could not be completely written.');
            }
            hash_update($hash, $sql);
        };
        $identifier = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
        $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            $stream = gzopen($temporary, 'wb6');
            if ($stream === false) {
                throw new RuntimeException('Cannot open the database snapshot.');
            }
            $mode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $mode = implode(
                ',',
                array_unique([...array_filter(explode(',', $mode)), 'NO_AUTO_VALUE_ON_ZERO']),
            );
            $timezone = (string) $db->query('SELECT @@SESSION.time_zone')->fetchColumn();
            $write(
                "-- Synapse pre-deployment database snapshot\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE=" .
                    $db->quote($mode) .
                    ";\nSET time_zone=" .
                    $db->quote($timezone) .
                    ";\n",
            );
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            foreach ($tables as $table) {
                $name = $identifier($table['TABLE_NAME']);
                $definition = $db->query('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM)[1];
                $write('DROP TABLE IF EXISTS ' . $name . ";\n" . $definition . ";\n");
                $columns = $binary = [];
                foreach ($db->query('SHOW FULL COLUMNS FROM ' . $name)->fetchAll() as $column) {
                    if (!preg_match('/(?:VIRTUAL|STORED) GENERATED/i', $column['Extra'])) {
                        $columns[] = $identifier($column['Field']);
                        $binary[] = (bool) preg_match(
                            '/^(?:tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i',
                            $column['Type'],
                        );
                    }
                }
                $projection = implode(', ', $columns);
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $data = $db->query('SELECT ' . $projection . ' FROM ' . $name);
                try {
                    while ($row = $data->fetch(PDO::FETCH_NUM)) {
                        $values = [];
                        foreach ($row as $index => $value) {
                            $values[] =
                                $value === null
                                    ? 'NULL'
                                    : ($binary[$index]
                                        ? "X'" . bin2hex((string) $value) . "'"
                                        : $db->quote((string) $value));
                        }
                        $write(
                            'INSERT INTO ' .
                                $name .
                                ' (' .
                                $projection .
                                ') VALUES (' .
                                implode(', ', $values) .
                                ");\n",
                        );
                        $rows++;
                    }
                } finally {
                    $data->closeCursor();
                    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                }
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n-- Synapse snapshot complete\n");
            if (!gzclose($stream)) {
                throw new RuntimeException('Cannot finish the database snapshot.');
            }
            $stream = null;
            $db->commit();
            $expected = hash_final($hash);
            $check = hash_init('sha256');
            $stream = gzopen($temporary, 'rb');
            while (!gzeof($stream)) {
                $chunk = gzread($stream, 65536);
                if ($chunk === false || (hrtime(true) - $started) / 1e9 > 55) {
                    throw new RuntimeException('Cannot verify the database snapshot.');
                }
                hash_update($check, $chunk);
            }
            gzclose($stream);
            $stream = null;
            if (!hash_equals($expected, hash_final($check))) {
                throw new RuntimeException('Database snapshot verification failed.');
            }
            $saved = [
                'release' => $release,
                'file' => $filename,
                'created_at_utc' => gmdate('c'),
                'tables' => count($tables),
                'rows' => $rows,
                'bytes' => filesize($temporary),
                'sha256' => hash_file('sha256', $temporary),
                'sql_sha256' => $expected,
            ];
            if (
                !rename($temporary, $destination) ||
                file_put_contents($manifest, json_encode($saved, JSON_THROW_ON_ERROR), LOCK_EX) ===
                    false
            ) {
                throw new RuntimeException('Cannot record the completed database snapshot.');
            }
            chmod($manifest, 0600);
            return $saved;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            if (is_resource($stream)) {
                gzclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }
}
