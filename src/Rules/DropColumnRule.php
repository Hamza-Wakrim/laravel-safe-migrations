<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class DropColumnRule implements Rule
{
    private const NOT_COLUMNS = ['index', 'foreign', 'primary', 'constraint', 'check', 'key', 'unique', 'fulltext', 'spatial'];

    public function id(): string
    {
        return 'drop-column';
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
            if (! preg_match('/^drop (?:column )?(?:if exists )?(\w+)/', $clause, $m) || in_array($m[1], self::NOT_COLUMNS, true)) {
                continue;
            }

            yield new Finding(
                $this->id(),
                Severity::Error,
                "Dropping column [{$table}.{$m[1]}] destroys its data, and app servers still running the old code will fail on queries that use it.",
                'Remove every use of the column from the code and deploy that first, then drop the column in a follow-up migration.',
                "{$table}.{$m[1]}",
            );
        }
    }
}
