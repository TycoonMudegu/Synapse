<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Schema
{
    public static function check(PDO $db, array $contract): void
    {
        $missing = self::missing($db, $contract);
        if ($missing !== []) {
            throw new RuntimeException(
                "Schema requirements not met:\n - " . implode("\n - ", $missing),
            );
        }
    }

    /** Only release-contract identifiers; never database records or SQL errors. */
    public static function missing(PDO $db, array $contract): array
    {
        $columns = $db
            ->query(
                'SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = \'BASE TABLE\'',
            )
            ->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($columns as $column) {
            $actual[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
        }
        $missing = [];
        foreach ($contract['tables'] as $table => $required) {
            if (!isset($actual[$table])) {
                $missing[] = "table {$table}";
                continue;
            }
            foreach (array_diff($required, $actual[$table]) as $column) {
                $missing[] = "{$table}.{$column}";
            }
        }
        $indexes = $db
            ->query(
                'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            )
            ->fetchAll(PDO::FETCH_ASSOC);
        $unique = [];
        foreach ($indexes as $index) {
            if ((int) $index['NON_UNIQUE'] === 0) {
                $unique[$index['TABLE_NAME']][$index['INDEX_NAME']][] =
                    $index['SUB_PART'] === null ? $index['COLUMN_NAME'] : '(prefix)';
            }
        }
        foreach ($contract['unique_indexes'] as $table => $requiredIndexes) {
            foreach ($requiredIndexes as $required) {
                if (!in_array($required, $unique[$table] ?? [], true)) {
                    $missing[] = "unique index {$table}(" . implode(', ', $required) . ')';
                }
            }
        }
        return $missing;
    }
}
