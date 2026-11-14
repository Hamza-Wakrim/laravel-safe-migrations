<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * PostgreSQL only: adding a validated foreign key locks both tables while every
 * existing row is checked.
 */
final class BlockingForeignKeyRule implements Rule
{
    public function id(): string
    {
        return 'blocking-foreign-key';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if ($statement->driver !== 'pgsql' || ! [$table, $clauses] = $statement->alterTable()) {
            return;
        }

        if ($context->isNew($table)) {
            return;
        }

        foreach ($clauses as $clause) {
            if (! preg_match('/^add (?:constraint (\w+) )?foreign key .* references ([\w.]+)/', $clause, $m) || str_contains($clause, 'not valid')) {
                continue;
            }

            $name = $m[1] !== '' ? $m[1] : 'foreign key';

            yield new Finding(
                $this->id(),
                Severity::Warning,
                "Adding foreign key [{$name}] validates every row of [{$table}] while blocking writes to [{$table}] and [{$m[2]}].",
                'Add the constraint with NOT VALID, then run ALTER TABLE ... VALIDATE CONSTRAINT in a separate migration (it only takes a light lock).',
                "{$table}.{$name}",
            );
        }
    }
}
