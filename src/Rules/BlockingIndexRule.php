<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * PostgreSQL only: plain CREATE INDEX (and unique / primary key constraints)
 * blocks all writes to the table until the index is built.
 */
final class BlockingIndexRule implements Rule
{
    public function id(): string
    {
        return 'blocking-index';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if ($statement->driver !== 'pgsql') {
            return;
        }

        if (preg_match('/^create (?:unique )?index (?!concurrently\b)(?:if not exists )?(\w+) on (?:only )?([\w.]+)/', $statement->normalized, $m)) {
            if (! $context->isNew($m[2])) {
                yield $this->finding($m[2], $m[1]);
            }

            return;
        }

        $alter = $statement->alterTable();

        if ($alter === null) {
            return;
        }

        [$table, $clauses] = $alter;

        if ($context->isNew($table)) {
            return;
        }

        foreach ($clauses as $clause) {
            if (preg_match('/^add (?:constraint (\w+) )?(?:unique|primary key)\b/', $clause, $m) && ! str_contains($clause, ' using index ')) {
                yield $this->finding($table, $m[1] ?? 'primary');
            }
        }
    }

    private function finding(string $table, string $index): Finding
    {
        return new Finding(
            $this->id(),
            Severity::Warning,
            "Building index [{$index}] on [{$table}] blocks every write to the table until it finishes.",
            'Create it with CREATE INDEX CONCURRENTLY in a migration with $withinTransaction = false (for unique constraints, build the index concurrently and then ADD CONSTRAINT ... USING INDEX).',
            "{$table}.{$index}",
        );
    }
}
