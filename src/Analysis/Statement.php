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
