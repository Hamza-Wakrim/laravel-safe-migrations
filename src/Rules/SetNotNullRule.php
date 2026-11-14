<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class SetNotNullRule implements Rule
{
    public function id(): string
    {
        return 'set-not-null';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        $alter = $statement->alterTable();

        if ($alter === null) {
            return;
        }

        [$table, $clauses] = $alter;

        if ($context->isNew($table)) {
            return;
        }

        foreach ($clauses as $clause) {
            if (! preg_match('/^alter (?:column )?(\w+) set not null/', $clause, $m)) {
                continue;
            }

            yield new Finding(
                $this->id(),
                Severity::Warning,
                "Setting [{$table}.{$m[1]}] to NOT NULL scans the whole table under an ACCESS EXCLUSIVE lock, and fails if any row is still NULL.",
                'On PostgreSQL 12+, first add CHECK ({column} IS NOT NULL) NOT VALID, VALIDATE it, then SET NOT NULL (no scan needed).',
                "{$table}.{$m[1]}",
            );
        }
    }
}
