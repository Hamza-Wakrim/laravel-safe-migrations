<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class RenameTableRule implements Rule
{
    public function id(): string
    {
        return 'rename-table';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if (! preg_match('/^(?:alter table ([\w.]+) rename to|rename table ([\w.]+) to) ([\w.]+)/', $statement->normalized, $m)) {
            return;
        }

        $from = $m[1] !== '' ? $m[1] : $m[2];

        if (str_starts_with($from, '__temp__') || $context->isNew($from)) {
            return;
        }

        yield new Finding(
            $this->id(),
            Severity::Error,
            "Renaming table [{$from}] to [{$m[3]}] breaks every running app server that still uses the old name.",
            'Create the new table, write to both, backfill, switch reads, then drop the old table in a later release.',
            $from,
        );
    }
}
