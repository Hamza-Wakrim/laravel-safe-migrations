<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class DropTableRule implements Rule
{
    public function id(): string
    {
        return 'drop-table';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if (! preg_match('/^drop table (?:if exists )?([\w.]+)/', $statement->normalized, $m)) {
            return;
        }

        $table = $m[1];

        if ($context->isNew($table) || $context->isRebuilding($table)) {
            return;
        }

        yield new Finding(
            $this->id(),
            Severity::Error,
            "Dropping table [{$table}] destroys its data and breaks any running code that still queries it.",
            'Ship code that no longer uses the table first, back it up, then drop it in a later release.',
            $table,
        );
    }
}
