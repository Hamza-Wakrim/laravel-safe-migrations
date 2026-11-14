<?php

namespace HamzaWakrim\SafeMigrations\Exceptions;

use RuntimeException;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Report;

final class UnsafeMigrationException extends RuntimeException
{
    public function __construct(string $message, public readonly Report $report)
    {
        parent::__construct($message);
    }

    public static function fromReport(Report $report): self
    {
        $lines = array_map(
            fn (Finding $f) => sprintf('  - [%s] %s: %s', $f->rule, $f->migration, $f->message),
            $report->findings,
        );

        return new self(
            sprintf("Refusing to migrate: %d unsafe operation(s) found in pending migrations.\n%s\n", count($report->findings), implode("\n", $lines))
            .'Run `php artisan safe-migrations:check` for details, mark reviewed migrations with #[AllowUnsafe], '
            .'or set SAFE_MIGRATIONS_ALLOW_UNSAFE=true for this run.',
            $report,
        );
    }
}
