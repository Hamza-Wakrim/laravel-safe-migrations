<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class RenameColumnRule implements Rule
{
    public function id(): string
    {
        return 'rename-column';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if (! [$table, $clauses] = $statement->alterTable()) {
            return;
        }

        if ($context->isNew($table)) {
            return;
        }

        foreach ($clauses as $clause) {
            if (preg_match('/^rename column (\w+) to (\w+)/', $clause, $m)
                || (preg_match('/^change (?:column )?(\w+) (\w+) /', $clause, $m) && $m[1] !== $m[2])) {
                yield new Finding(
                    $this->id(),
                    Severity::Error,
                    "Renaming column [{$table}.{$m[1]}] to [{$m[2]}] breaks every running app server that still uses the old name.",
                    'Add the new column, write to both, backfill, switch reads, then drop the old column in a later release.',
                    "{$table}.{$m[1]}",
                );
            }
        }
    }
}
