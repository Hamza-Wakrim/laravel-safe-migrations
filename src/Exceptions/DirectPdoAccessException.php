<?php

namespace HamzaWakrim\SafeMigrations\Exceptions;

use RuntimeException;

/**
 * Thrown when a migration bypasses Laravel and executes SQL on PDO directly
 * during the dry run. The statement is blocked, and the migration is reported
 * as analysis-failed so it gets a manual review.
 */
final class DirectPdoAccessException extends RuntimeException
{
    public static function for(string $sql): self
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return new self(sprintf(
            'it executes SQL on the PDO connection directly, which the dry run blocked instead of running: %s',
            strlen($sql) > 120 ? substr($sql, 0, 117).'...' : $sql,
        ));
    }
}
