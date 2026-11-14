<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * Flags migrations that rewrite or wipe whole tables of data.
 */
final class DataMutationRule implements Rule
{
    public function id(): string
    {
        return 'data-mutation';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        $sql = $statement->normalized;

        if (preg_match('/^truncate (?:table )?([\w.]+)/', $sql, $m)) {
            $table = $m[1];
            $message = "Truncating [{$table}] deletes every row.";
        } elseif (preg_match('/^delete from ([\w.]+)/', $sql, $m) && ! preg_match('/\bwhere\b/', $sql)) {
            $table = $m[1];
            $message = "Deleting from [{$table}] without a WHERE clause removes every row.";
        } elseif (preg_match('/^update ([\w.]+) set /', $sql, $m) && ! preg_match('/\bwhere\b/', $sql)) {
            $table = $m[1];
            $message = "Updating [{$table}] without a WHERE clause rewrites every row in one long transaction.";
        } else {
            return;
        }

        if ($context->isNew($table)) {
            return;
        }

        yield new Finding(
            $this->id(),
            Severity::Warning,
            $message,
            'Move data changes into a queued job or command that works in batches, and keep migrations for schema changes.',
            $table,
        );
    }
}
