<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\MySqlAlter;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * MySQL / MariaDB: an explicit ALGORITHM= or LOCK= the operation cannot use
 * makes the server reject the statement, so the migration fails as soon as
 * it runs in production.
 */
final class UnsupportedAlgorithmRule implements Rule
{
    /** Operations that can never run with ALGORITHM=INSTANT (MySQL and MariaDB). */
    private const NOT_INSTANT = [
        MySqlAlter::ADD_INDEX, MySqlAlter::ADD_FULLTEXT, MySqlAlter::ADD_SPATIAL, MySqlAlter::ADD_PRIMARY,
        MySqlAlter::DROP_PRIMARY, MySqlAlter::ADD_FOREIGN, MySqlAlter::CONVERT_CHARSET, MySqlAlter::ENGINE,
        MySqlAlter::AUTO_INCREMENT_COLUMN, MySqlAlter::STORED_COLUMN,
    ];

    /** Operations that rebuild or copy the table, which MariaDB's ALGORITHM=NOCOPY refuses. */
    private const NOT_NOCOPY = [
        MySqlAlter::ADD_PRIMARY, MySqlAlter::DROP_PRIMARY, MySqlAlter::CONVERT_CHARSET,
        MySqlAlter::AUTO_INCREMENT_COLUMN, MySqlAlter::STORED_COLUMN,
    ];

    public function id(): string
    {
        return 'unsupported-algorithm';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        $alter = MySqlAlter::from($statement);

        if ($alter === null) {
            return;
        }

        $copies = $alter->copyOperations($context->foreignKeyChecks($statement));

        $conflicts = match ($alter->algorithm) {
            'instant' => array_intersect($alter->operations, self::NOT_INSTANT),
            'inplace' => array_diff($copies, ['algorithm-copy']),
            'nocopy' => [...array_intersect($alter->operations, self::NOT_NOCOPY), ...array_intersect($copies, [MySqlAlter::ADD_FOREIGN])],
            default => [],
        };

        if ($conflicts !== []) {
            yield $this->finding($statement, $alter->table, 'ALGORITHM='.strtoupper((string) $alter->algorithm), $conflicts);
        }

        // MySQL only: since 11.2, MariaDB can copy a table with LOCK=NONE (writes go on during the copy),
        // so whether it rejects LOCK=NONE depends on the server version.
        if ($alter->lock === 'none' && $statement->driver === 'mysql') {
            $conflicts = array_unique([...$alter->writeBlockingOperations(), ...$copies]);

            if ($conflicts !== []) {
                yield $this->finding($statement, $alter->table, 'LOCK=NONE', $conflicts);
            }
        }
    }

    /** @param  array<string>  $operations */
    private function finding(Statement $statement, string $table, string $option, array $operations): Finding
    {
        $server = $statement->driver === 'mariadb' ? 'MariaDB' : 'MySQL';
        $what = implode(', ', array_map(MySqlAlter::describe(...), array_values(array_unique($operations))));

        return new Finding(
            $this->id(),
            Severity::Error,
            "{$server} rejects {$option} for {$what} on [{$table}], so this migration fails when it runs.",
            "Remove {$option}, or move {$what} to an ALTER TABLE of its own without it.",
            "{$table}:".strtolower($option),
        );
    }
}
