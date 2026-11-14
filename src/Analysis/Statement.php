<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

/**
 * A single SQL statement a migration would execute.
 */
final class Statement
{
    /** Lower-cased SQL with identifier quotes stripped and whitespace collapsed. */
    public readonly string $normalized;

    public function __construct(
        public readonly string $sql,
        public readonly string $driver,
        public readonly ?string $migration = null,
    ) {
        $this->normalized = self::normalize($sql);
    }

    public static function normalize(string $sql): string
    {
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
        if (! preg_match('/^alter table (?:only )?(?:if exists )?([\w.]+) (.+)$/', $this->normalized, $m)) {
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
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Quoted strings and identifiers: copy up to the matching close quote.
            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i + 1;

                while ($end < $length && $sql[$end] !== $char) {
                    $end += $sql[$end] === '\\' ? 2 : 1;
                }

                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            // -- line comments and /* block comments */
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            // PostgreSQL dollar quoting: $$ ... $$ or $tag$ ... $tag$.
            if ($char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $end = $end === false ? $length : $end + strlen($m[0]);
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;

                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
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
