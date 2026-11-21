<?php

declare(strict_types=1);

namespace App\Core\Database\Migrations;

/**
 * Splits a migration file into individual statements on top-level semicolons,
 * so PDO can execute them one at a time.
 *
 * Understands '...', "...", `...` (with doubled-quote and backslash escapes),
 * -- and # line comments, and block comments. It does NOT support DELIMITER;
 * migrations must not use it (decisions.md D-020). A single-statement trigger
 * body ("FOR EACH ROW SIGNAL ...;") is fine.
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        if (preg_match('/^\s*DELIMITER\s/im', $sql) === 1) {
            throw new MigrationException('DELIMITER is a mysql-client command and cannot run through PDO. Rewrite the statement (decisions.md D-020).');
        }

        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if (($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r", ''], true)) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new MigrationException('Unterminated block comment.');
                }
                $i = $end + 1;
                $buffer .= ' ';
                continue;
            }

            if ($char === ';') {
                self::push($statements, $buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($quote !== null) {
            throw new MigrationException('Unterminated quoted string.');
        }
        self::push($statements, $buffer);

        return $statements;
    }

    /** @param list<string> $statements */
    private static function push(array &$statements, string $buffer): void
    {
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
}
