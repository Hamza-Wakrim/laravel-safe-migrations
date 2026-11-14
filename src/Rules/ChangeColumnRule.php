<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class ChangeColumnRule implements Rule
{
    public function id(): string
    {
        return 'change-column';
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
            $column = null;

            if (preg_match('/^modify (?:column )?(\w+) /', $clause, $m)) {
                $column = $m[1];
            } elseif (preg_match('/^change (?:column )?(\w+) (\w+) /', $clause, $m) && $m[1] === $m[2]) {
                $column = $m[1];
            } elseif (preg_match('/^alter (?:column )?(\w+) (?:set data )?type /', $clause, $m)) {
                $column = $m[1];
            }

            if ($column === null) {
                continue;
            }

            yield new Finding(
                $this->id(),
                Severity::Warning,
                "Changing the definition of [{$table}.{$column}] can rewrite the whole table while holding a lock that blocks reads and writes.",
                'Check the lock behaviour for your database and table size. For large tables, add a new column, backfill it in batches and swap.',
                "{$table}.{$column}",
            );
        }
    }
}
