<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

final class Sql
{
    /** Split statements or DDL clauses without splitting quoted text or parentheses. */
    public static function split(string $sql, string $separator = ';'): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif (
                $char === '#' ||
                ($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' '))
            ) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            } elseif ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Unclosed SQL comment.');
                }
                if (($sql[$i + 2] ?? '') === '!') {
                    throw new RuntimeException(
                        'Executable SQL comments are unsupported in migrations.',
                    );
                }
                $i = $end + 1;
                $buffer .= ' ';
                continue;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    throw new RuntimeException('Unbalanced SQL parentheses.');
                }
            } elseif ($char === $separator && $depth === 0) {
                if (trim($buffer) !== '') {
                    $parts[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException('Unclosed SQL quote or parentheses.');
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        return $parts;
    }
}
