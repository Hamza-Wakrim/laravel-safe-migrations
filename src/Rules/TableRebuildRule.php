<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * SQLite cannot alter most column definitions in place, so Laravel copies the
 * table into "__temp__<table>", drops the original and renames the copy.
 */
final class TableRebuildRule implements Rule
{
    public function id(): string
    {
        return 'table-rebuild';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if (! preg_match('/^create table (?:if not exists )?__temp__([\w.]+)/', $statement->normalized, $m) || $context->isNew($m[1])) {
            return;
        }

        yield new Finding(
            $this->id(),
            Severity::Warning,
            "SQLite will rebuild table [{$m[1]}] (copy every row, drop the original, rename the copy), locking the database for the duration.",
            'Fine for small tables. For large ones, schedule it in a maintenance window or add a new column instead of changing one.',
            $m[1],
        );
    }
}
