<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * Every ALTER needs a metadata / ACCESS EXCLUSIVE lock, even an INSTANT one.
 * If a long query holds the table, the ALTER waits for it, and every query
 * after the ALTER queues behind it: the table is down until the slow query
 * ends. A lock timeout makes the ALTER give up instead (retry the deploy).
 *
 * Off by default: Laravel's schema builder never sets a timeout, so this
 * would flag every ALTER. Turn it on with 'lock-timeout' => 'warning'.
 */
final class LockTimeoutRule implements OptInRule
{
    public function id(): string
    {
        return 'lock-timeout';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        $mysql = in_array($statement->driver, ['mysql', 'mariadb'], true);

        if (! $mysql && $statement->driver !== 'pgsql') {
            return;
        }

        $table = $this->lockedTable($statement);

        if ($table === null || $context->isNew($table) || $context->hasLockTimeout($statement)) {
            return;
        }

        if ($mysql && $this->hasWaitClause($statement)) {
            return;
        }

        yield new Finding(
            $this->id(),
            Severity::Warning,
            "This statement waits for a lock on [{$table}] with no timeout. If a long query is running on the table, it waits, and every query after it waits too.",
            match ($statement->driver) {
                'pgsql' => "Run DB::statement(\"SET lock_timeout = '5s'\") first in up(), and retry the migration if it times out.",
                'mariadb' => 'Add WAIT n or NOWAIT to the statement (ALTER TABLE t WAIT 5 ...), or run DB::statement(\'SET SESSION lock_wait_timeout = 5\') first in up().',
                default => 'Run DB::statement(\'SET SESSION lock_wait_timeout = 5\') first in up() (on MariaDB you can also use ALTER TABLE t WAIT 5 ...), and retry the migration if it times out.',
            },
            $table,
        );
    }

    private function lockedTable(Statement $statement): ?string
    {
        $sql = $statement->normalized;

        if (($alter = $statement->alterTable()) !== null) {
            return $alter[0];
        }

        // CONCURRENTLY does not hold the table lock while it builds, so it is not flagged here.
        if (preg_match('/^create (?:unique |fulltext |spatial )?index (?!concurrently\b)(?:if not exists )?[\w.]+ on (?:only )?([\w.]+)/', $sql, $m)) {
            return $m[1];
        }

        if (preg_match('/^rename table ([\w.]+) /', $sql, $m)) {
            return $m[1];
        }

        return null;
    }

    /** MariaDB: ALTER TABLE t WAIT n / NOWAIT, or SET STATEMENT lock_wait_timeout=n FOR ... */
    private function hasWaitClause(Statement $statement): bool
    {
        if ($statement->statementOptions !== null && preg_match('/\block_wait_timeout ?= ?[1-9]/', $statement->statementOptions)) {
            return true;
        }

        return (bool) preg_match('/ (?:wait \d+(?:\.\d+)?|nowait)(?: |$)/', $statement->normalized);
    }
}
