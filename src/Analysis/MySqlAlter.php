<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

/**
 * What a MySQL / MariaDB ALTER TABLE (or CREATE INDEX) does, in terms of the
 * online DDL matrix: the operations it holds and the ALGORITHM / LOCK it asks for.
 *
 * Only operations whose behaviour does not depend on the current table
 * definition are classified, so a finding never rests on a guess about the
 * column's old type.
 */
final class MySqlAlter
{
    public const ADD_INDEX = 'add-index';

    public const ADD_FULLTEXT = 'add-fulltext';

    public const ADD_SPATIAL = 'add-spatial';

    public const ADD_PRIMARY = 'add-primary';

    public const DROP_PRIMARY = 'drop-primary';

    public const ADD_FOREIGN = 'add-foreign';

    public const CONVERT_CHARSET = 'convert-charset';

    public const ENGINE = 'engine';

    public const AUTO_INCREMENT_COLUMN = 'auto-increment-column';

    public const STORED_COLUMN = 'stored-column';

    /**
     * @param  list<string>  $operations
     */
    private function __construct(
        public readonly string $table,
        public readonly array $operations,
        public readonly ?string $algorithm,
        public readonly ?string $lock,
    ) {}

    public static function from(Statement $statement): ?self
    {
        if (! in_array($statement->driver, ['mysql', 'mariadb'], true)) {
            return null;
        }

        if (($alter = $statement->alterTable()) !== null) {
            return self::fromClauses($alter[0], $alter[1]);
        }

        // CREATE [UNIQUE|FULLTEXT|SPATIAL] INDEX i ON t (...) [ALGORITHM [=] x] [LOCK [=] y]
        if (preg_match('/^create (?:(unique|fulltext|spatial) )?index (?:if not exists )?[\w.]+ (?:using \w+ )?on ([\w.]+) (.*)$/', $statement->normalized, $m)) {
            $operation = match ($m[1]) {
                'fulltext' => self::ADD_FULLTEXT,
                'spatial' => self::ADD_SPATIAL,
                default => self::ADD_INDEX,
            };

            return new self($m[2], [$operation], self::option('algorithm', $m[3]), self::option('lock', $m[3]));
        }

        return null;
    }

    /** @param  list<string>  $clauses */
    private static function fromClauses(string $table, array $clauses): self
    {
        $operations = [];
        $algorithm = null;
        $lock = null;

        foreach ($clauses as $clause) {
            if (preg_match('/^algorithm ?=? ?(\w+)$/', $clause, $m)) {
                $algorithm = $m[1];

                continue;
            }

            if (preg_match('/^lock ?=? ?(\w+)$/', $clause, $m)) {
                $lock = $m[1];

                continue;
            }

            $operation = self::classify($clause);

            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return new self($table, array_values(array_unique($operations)), $algorithm, $lock);
    }

    private static function classify(string $clause): ?string
    {
        return match (true) {
            (bool) preg_match('/^add (?:constraint (?:\w+ )?)?foreign key\b/', $clause) => self::ADD_FOREIGN,
            (bool) preg_match('/^add (?:constraint (?:\w+ )?)?primary key\b/', $clause) => self::ADD_PRIMARY,
            $clause === 'drop primary key' => self::DROP_PRIMARY,
            (bool) preg_match('/^add fulltext\b/', $clause) => self::ADD_FULLTEXT,
            (bool) preg_match('/^add spatial\b/', $clause) => self::ADD_SPATIAL,
            (bool) preg_match('/^add (?:constraint (?:\w+ )?)?(?:unique|index|key)\b/', $clause) => self::ADD_INDEX,
            (bool) preg_match('/^convert to (?:character set|charset)\b/', $clause) => self::CONVERT_CHARSET,
            (bool) preg_match('/^engine ?=? ?\w+$/', $clause) => self::ENGINE,
            (bool) preg_match('/^add (?:column )?(?:if not exists )?\w+ .*\bas ?\(.*\) ?(?:stored|persistent)\b/', $clause) => self::STORED_COLUMN,
            (bool) preg_match('/^add (?:column )?(?:if not exists )?\w+ .*\bauto_increment\b/', $clause) => self::AUTO_INCREMENT_COLUMN,
            default => null,
        };
    }

    private static function option(string $name, string $sql): ?string
    {
        return preg_match('/\b'.$name.' ?=? ?(\w+)\b/', $sql, $m) ? $m[1] : null;
    }

    /** A few words for messages: "adding a foreign key". */
    public static function describe(string $operation): string
    {
        return match ($operation) {
            self::ADD_INDEX => 'adding an index',
            self::ADD_FULLTEXT => 'adding a FULLTEXT index',
            self::ADD_SPATIAL => 'adding a SPATIAL index',
            self::ADD_PRIMARY => 'adding a primary key',
            self::DROP_PRIMARY => 'dropping the primary key',
            self::ADD_FOREIGN => 'adding a foreign key',
            self::CONVERT_CHARSET => 'converting the character set',
            self::ENGINE => 'changing the engine',
            self::AUTO_INCREMENT_COLUMN => 'adding an AUTO_INCREMENT column',
            self::STORED_COLUMN => 'adding a STORED generated column',
            'algorithm-copy' => 'ALGORITHM=COPY',
            default => $operation,
        };
    }

    public function has(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Operations that make MySQL / MariaDB copy the whole table (ALGORITHM=COPY):
     * writes are blocked until the copy finishes.
     *
     * @return list<string>
     */
    public function copyOperations(bool $foreignKeyChecks): array
    {
        $copy = [];

        foreach ($this->operations as $operation) {
            $copies = match ($operation) {
                // In place only with foreign_key_checks off.
                self::ADD_FOREIGN => $foreignKeyChecks,
                // Dropping the primary key without adding a new one in the same statement.
                self::DROP_PRIMARY => ! $this->has(self::ADD_PRIMARY),
                self::CONVERT_CHARSET, self::STORED_COLUMN => true,
                default => false,
            };

            if ($copies) {
                $copy[] = $operation;
            }
        }

        if ($this->algorithm === 'copy') {
            $copy[] = 'algorithm-copy';
        }

        return $copy;
    }

    /**
     * Operations that run in place but still block writes for the whole run (no LOCK=NONE).
     *
     * @return list<string>
     */
    public function writeBlockingOperations(): array
    {
        return array_values(array_intersect($this->operations, [self::ADD_FULLTEXT, self::ADD_SPATIAL, self::AUTO_INCREMENT_COLUMN]));
    }
}
