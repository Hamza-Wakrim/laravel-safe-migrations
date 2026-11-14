<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class AddNotNullColumnRule implements Rule
{
    public function id(): string
    {
        return 'add-not-null-column';
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
            if (! preg_match('/^add (?:column )?(?!(?:constraint|index|unique|primary|foreign|fulltext|spatial|key)\b)(\w+) (.*)$/', $clause, $m)) {
                continue;
            }

            $definition = $m[2];

            if (! preg_match('/\bnot null\b/', $definition)
                || preg_match('/\b(default|auto_increment|autoincrement|generated|identity|(?:big|small)?serial)\b|\bas \(/', $definition)) {
                continue;
            }

            $mysql = in_array($statement->driver, ['mysql', 'mariadb'], true);

            yield new Finding(
                $this->id(),
                $mysql ? Severity::Warning : Severity::Error,
                $mysql
                    ? "Adding NOT NULL column [{$table}.{$m[1]}] without a default makes MySQL silently fill existing rows with an implicit value (0, '' or a zero date)."
                    : "Adding NOT NULL column [{$table}.{$m[1]}] without a default fails as soon as the table has rows.",
                'Add the column as nullable (or with a default), backfill existing rows, then make it NOT NULL in a later migration.',
                "{$table}.{$m[1]}",
            );
        }
    }
}
