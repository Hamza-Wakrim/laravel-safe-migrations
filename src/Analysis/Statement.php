<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

/**
 * A single SQL statement a migration would execute.
 */
final class Statement
{
    /** Lower-cased SQL with comments and identifier quotes stripped and whitespace collapsed. */
    public readonly string $normalized;

    /**
     * Options of a MariaDB "SET STATEMENT <options> FOR <statement>" prefix,
     * e.g. "lock_wait_timeout=5". The prefix itself is removed from $normalized
     * so rules see the statement it wraps.
     */
    public readonly ?string $statementOptions;

    public function __construct(
        public readonly string $sql,
        public readonly string $driver,
        public readonly ?string $migration = null,
        /** Whether Laravel runs this statement inside the migration's transaction. */
        public readonly bool $inTransaction = false,
    ) {
        $normalized = self::normalize($sql);
        $options = null;

        if (preg_match('/^set statement (.+?) for (.+)$/', $normalized, $m)) {
            [$options, $normalized] = [$m[1], $m[2]];
        }

        $this->statementOptions = $options;
        $this->normalized = $normalized;
    }

    public static function normalize(string $sql): string
    {
        $sql = self::stripComments($sql);
        $sql = str_replace(['`', '"'], '', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return rtrim(strtolower(trim($sql)), '; ');
    }

    /**
     * Parse "alter table <table> <clause>, <clause>..." statements.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public function alterTable(): ?array
    {
        if (! preg_match('/^alter table (?:only )?(?:if exists )?([\w.]+) (?:(?:wait \d+(?:\.\d+)?|nowait) )?(.+)$/', $this->normalized, $m)) {
            return null;
        }

        return [$m[1], self::splitClauses($m[2])];
    }

    /**
     * Split a string holding several SQL statements on top-level semicolons.
     *
     * Semicolons inside quotes, quoted identifiers, comments and PostgreSQL
     * dollar-quoted bodies ($$ ... $$, $tag$ ... $tag$) do not split.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';

        foreach (self::tokens($sql) as [$kind, $text]) {
            if ($kind === 'code' && $text === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $text;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Remove -- line comments and /* block comments *\/ so they cannot hide a
     * statement from the rules. Quoted strings and dollar-quoted bodies are left
     * untouched. MySQL executable comments (/*! ... *\/) are kept as SQL,
     * because MySQL runs what is inside them.
     */
    public static function stripComments(string $sql): string
    {
        $result = '';

        foreach (self::tokens($sql) as [$kind, $text]) {
            if ($kind !== 'comment') {
                $result .= $text;
            } elseif (str_starts_with($text, '/*!')) {
                // /*!50110 KEY_BLOCK_SIZE=1024 */: drop the marker and optional version number.
                $result .= ' '.preg_replace('/^\/\*!\d*|\*\/$/', '', $text).' ';
            } else {
                $result .= ' ';
            }
        }

        return $result;
    }

    /**
     * Walk the SQL and yield its pieces: quoted strings and identifiers,
     * comments, PostgreSQL dollar-quoted bodies, and single characters of code.
     *
     * @return \Generator<int, array{0: 'quoted'|'comment'|'dollar'|'code', 1: string}>
     */
    private static function tokens(string $sql): \Generator
    {
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Quoted strings and identifiers: up to the matching close quote.
            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i + 1;

                while ($end < $length && $sql[$end] !== $char) {
                    $end += $sql[$end] === '\\' ? 2 : 1;
                }

                yield ['quoted', substr($sql, $i, $end - $i + 1)];
                $i = $end;

                continue;
            }

            // -- line comments (up to, not including, the newline).
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                yield ['comment', substr($sql, $i, $end - $i)];
                $i = $end - 1;

                continue;
            }

            // /* block comments */
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                yield ['comment', substr($sql, $i, $end - $i)];
                $i = $end - 1;

                continue;
            }

            // PostgreSQL dollar quoting: $$ ... $$ or $tag$ ... $tag$.
            if ($char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $end = $end === false ? $length : $end + strlen($m[0]);
                yield ['dollar', substr($sql, $i, $end - $i)];
                $i = $end - 1;

                continue;
            }

            yield ['code', $char];
        }
    }

    /**
     * Split on top-level commas, ignoring commas inside parentheses or string literals.
     *
     * @return list<string>
     */
    public static function splitClauses(string $sql): array
    {
        $clauses = [];
        $depth = 0;
        $inString = false;
        $current = '';

        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                $inString = ! $inString;
            } elseif (! $inString && $char === '(') {
                $depth++;
            } elseif (! $inString && $char === ')') {
                $depth--;
            } elseif (! $inString && $depth === 0 && $char === ',') {
                $clauses[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $clauses[] = trim($current);
        }

        return $clauses;
    }
}
