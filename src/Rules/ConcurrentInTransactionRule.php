<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

/**
 * PostgreSQL only: CONCURRENTLY operations cannot run inside a transaction
 * block, and Laravel wraps PostgreSQL migrations in one unless the migration
 * sets $withinTransaction = false. The migration fails as soon as it runs.
 */
final class ConcurrentInTransactionRule implements Rule
{
    private const PATTERNS = [
        '/^create (?:unique )?index concurrently\b/',
        '/^drop index concurrently\b/',
        '/^reindex\b.*\bconcurrently\b/',
        '/^alter table .* detach partition [\w.]+ concurrently\b/',
    ];

    public function id(): string
    {
        return 'concurrent-in-transaction';
    }

    public function inspect(Statement $statement, Context $context): iterable
    {
        if ($statement->driver !== 'pgsql' || ! $statement->inTransaction) {
            return;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $statement->normalized)) {
                yield new Finding(
                    $this->id(),
                    Severity::Error,
                    'CONCURRENTLY cannot run inside a transaction, and this migration runs in one, so PostgreSQL will reject it.',
                    'Add public $withinTransaction = false; to the migration, and keep the concurrent statement in a migration of its own.',
                );

                return;
            }
        }
    }
}
