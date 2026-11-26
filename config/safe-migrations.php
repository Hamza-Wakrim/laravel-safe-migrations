<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Every rule that runs against the SQL your pending migrations would
    | execute. Set a rule to "error" or "warning" to override its severity,
    | false to turn it off, or null to keep its built-in severity.
    |
    | Errors make `safe-migrations:check` / `migrate:safe` fail. Warnings only
    | fail with --strict (or guard.strict below).
    |
    */

    'rules' => [
        'drop-table' => null,           // error
        'drop-column' => null,          // error
        'rename-table' => null,         // error
        'rename-column' => null,        // error
        'add-not-null-column' => null,  // error (warning on MySQL, which backfills silently)
        'change-column' => null,        // warning
        'set-not-null' => null,         // warning  (PostgreSQL)
        'blocking-index' => null,       // warning  (PostgreSQL, CREATE INDEX without CONCURRENTLY)
        'concurrent-in-transaction' => null, // error (PostgreSQL, CONCURRENTLY inside a transactional migration)
        'blocking-foreign-key' => null, // warning  (PostgreSQL, foreign key without NOT VALID)
        'table-rebuild' => null,        // warning  (SQLite table copy for ->change() etc.)
        'data-mutation' => null,        // warning  (UPDATE / DELETE without WHERE, TRUNCATE)
        'analysis-failed' => null,      // warning  (the migration threw an error during the dry run)
        'lock-timeout' => null,         // off unless set to 'warning' or 'error' (ALTER without a lock timeout)
        'unsafe-rollback' => null,      // warning  (down() breaks something that existed before up())
        'missing-down' => null,         // off unless set to 'warning' or 'error' (no down(), or it does nothing)
        'schema-condition' => null,     // warning  (branch on Schema::hasColumn() etc. the dry run skipped)
        'reads-data' => null,           // warning  (up() reads rows: DB::table()->get(), User::all(), ...)
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored migrations
    |--------------------------------------------------------------------------
    |
    | Migration names (file name without .php) that are never checked. To allow
    | a single migration, prefer the #[AllowUnsafe] attribute on the migration
    | itself so the decision lives next to the code.
    |
    */

    'ignore_migrations' => [
        // '2024_01_01_000000_drop_legacy_tables',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard `php artisan migrate`
    |--------------------------------------------------------------------------
    |
    | When enabled, the plain `migrate` command refuses to start in the listed
    | environments if a pending migration is unsafe. Set
    | SAFE_MIGRATIONS_ALLOW_UNSAFE=true for a one-off run you have reviewed.
    |
    */

    'guard' => [
        'enabled' => env('SAFE_MIGRATIONS_GUARD', false),
        'environments' => ['production'],
        'strict' => env('SAFE_MIGRATIONS_STRICT', false),
        'allow_unsafe' => env('SAFE_MIGRATIONS_ALLOW_UNSAFE', false),
    ],

];
