<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\MySqlAlter;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * MySQL / MariaDB: most ALTERs run INSTANT or in place while writes go on,
 * but some copy the whole table (ALGORITHM=COPY) or block writes until they
 * finish. On a large table that is minutes of downtime.
 */
final class TableCopyRule implements Rule
{
    public function id(): string
    {
        return 'table-copy';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        $alter = MySqlAlter::from($statement);

        if ($alter === null || $context->isNew($alter->table) || $alter->lock === 'none') {
            return;
        }

        $table = $alter->table;

        // An explicit algorithm makes the server refuse instead of copying (unsupported-algorithm reports when it would).
        if (! in_array($alter->algorithm, ['instant', 'inplace', 'nocopy'], true)) {
            foreach ($alter->copyOperations($context->foreignKeyChecks($statement)) as $operation) {
                yield $this->finding(
                    $statement,
                    $table,
                    $operation,
                    ucfirst(MySqlAlter::describe($operation))." on [{$table}] copies the whole table (ALGORITHM=COPY). ".$this->writes($statement, $table, 'the copy'),
                    $this->fix($operation),
                );
            }
        }

        if ($alter->algorithm === 'instant') {
            return;
        }

        foreach ($alter->writeBlockingOperations() as $operation) {
            yield $this->finding(
                $statement,
                $table,
                $operation,
                ucfirst(MySqlAlter::describe($operation))." on [{$table}] can't run with LOCK=NONE. ".$this->writes($statement, $table, 'it'),
                'For a large table, use an online schema change tool (gh-ost, pt-online-schema-change) or a maintenance window.',
            );
        }
    }

    private function writes(Statement $statement, string $table, string $what): string
    {
        return $statement->driver === 'mariadb'
            ? "Before MariaDB 11.2, writes to [{$table}] are blocked until {$what} finishes; from 11.2 writes go on, but it still takes time, disk space and replication lag on a large table."
            : "Writes to [{$table}] are blocked until {$what} finishes.";
    }

    private function fix(string $operation): string
    {
        return match ($operation) {
            MySqlAlter::ADD_FOREIGN => 'Add it inside Schema::withoutForeignKeyConstraints(fn () => ...): with foreign_key_checks off it is added in place, without a copy. Existing rows are then not checked against the parent table, so make sure they are valid first.',
            MySqlAlter::DROP_PRIMARY => 'Drop the old primary key and add the new one in the same ALTER TABLE, which runs in place.',
            MySqlAlter::STORED_COLUMN => 'Add it as a VIRTUAL generated column (->virtualAs()), which is instant, and index it if you need it to be fast.',
            'algorithm-copy' => 'Remove ALGORITHM=COPY so the server can pick an in-place algorithm.',
            default => 'For a large table, use an online schema change tool (gh-ost, pt-online-schema-change) or a maintenance window.',
        };
    }

    private function finding(Statement $statement, string $table, string $operation, string $message, string $fix): Finding
    {
        $guard = $statement->driver === 'mariadb'
            ? ' To make sure an ALTER never copies silently, add ALGORITHM=NOCOPY (or INSTANT) and LOCK=NONE: MariaDB then refuses instead of copying.'
            : ' To make sure an ALTER never copies silently, add ALGORITHM=INPLACE (or INSTANT) and LOCK=NONE: MySQL then refuses instead of copying.';

        return new Finding($this->id(), Severity::Warning, $message, $fix.$guard, "{$table}:{$operation}");
    }
}
