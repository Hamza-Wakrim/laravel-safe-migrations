# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **HTTP calls, mail, notifications and jobs in a migration ran during the check.** Pretend mode only stops database statements, but the check runs `up()` for real, so `Http::post(...)`, `Mail::...`, `Notification::...`, `Queue::push(...)` and `dispatch(...)` really happened during `safe-migrations:check`, `migrate:safe` and the guard. They are now swapped for Laravel's fakes while the migration is analysed, and the real services are put back afterwards. Other side effects (files, cache, events, plain PHP) still run; the README lists them under Limitations.

## [1.1.0] - 2026-11-25

### Added

- **`concurrent-in-transaction` rule (PostgreSQL, error).** Flags `CREATE INDEX CONCURRENTLY`, `DROP INDEX CONCURRENTLY`, `REINDEX ... CONCURRENTLY` and `DETACH PARTITION ... CONCURRENTLY` in a migration that Laravel runs inside a transaction. PostgreSQL rejects these statements there, so the migration would fail in production. The fix is `public $withinTransaction = false;`. Rules can now see whether a statement runs inside the migration's transaction (`Statement::$inTransaction`), decided the same way Laravel's migrator does.

### Fixed

- **Raw SQL in a migration with its own `$connection` was checked against the wrong database.** `migrate` makes the migration's connection the default while `up()` runs, so `DB::statement()` goes there. The dry run did not, so such statements were checked with the default connection's rules (for example as SQLite instead of PostgreSQL). The dry run now switches the default connection the same way.

## [1.0.4] - 2026-11-15

### Fixed

- **SQL comments could hide a statement from the check.** Raw SQL with a `--` or `/* */` comment before or inside a statement (for example `-- cleanup` followed by `DROP TABLE logs`) was not flagged. Comments are now removed before the rules run. Comment markers inside quoted strings are left alone, a commented-out statement is not reported, and MySQL executable comments (`/*! ... */`) are checked as SQL.

## [1.0.3] - 2026-11-15

### Fixed

- When a migration is reported as `analysis-failed` because it sends SQL straight to PDO (`DB::getPdo()->exec(...)`), the suggestion now says to use `DB::statement()` or `DB::unprepared()` so the SQL can be checked, instead of the generic hint about migrations that read data.

## [1.0.2] - 2026-11-14

### Fixed

- **The dry run could change other databases.** When a migration also used another connection (`Schema::connection('other')`, `DB::connection('other')`), those statements ran for real during `safe-migrations:check`, `migrate:safe` and the guard. Every configured connection is now in pretend mode during the check, and statements on other connections are analysed too.
- **Raw SQL sent straight to PDO ran during the dry run.** `DB::getPdo()->exec(...)` bypasses Laravel's pretend mode. During the check every connection now gets a guard PDO that blocks such calls, and the migration is reported as `analysis-failed`.
- `DB::unprepared()` with several statements in one string only had its first statement checked. Each statement is now checked (PostgreSQL `$$` function bodies are kept whole).
- Avoid passing `null` to `Migrator::usingConnection()` when no `--database` is given.

### Added

- `analysis-failed` is now a normal rule: set its severity or turn it off in the config, or allow it with `#[AllowUnsafe('analysis-failed')]`.
- `safe-migrations:check` rejects an unknown `--format` instead of silently printing the table.
- Static analysis with Larastan (level 8) and tests against the oldest supported dependency versions in CI.
- End-to-end tests of the commands on real MySQL and PostgreSQL.

## [1.0.1] - 2026-11-14

### Changed

- Re-release of 1.0.0 from cleaned-up git history (commit messages only). No code changes. Use 1.0.1 instead of 1.0.0.

## [1.0.0] - 2026-11-14

### Added

- `safe-migrations:check` command that dry-runs pending migrations and reports unsafe operations (table and JSON output, `--strict`, `--all`).
- `migrate:safe` command that checks pending migrations before running `migrate`.
- Optional guard that stops the plain `migrate` command in chosen environments.
- `#[AllowUnsafe]` attribute to mark reviewed migrations.
- Rules: `drop-table`, `drop-column`, `rename-table`, `rename-column`, `add-not-null-column`, `change-column`, `set-not-null`, `blocking-index`, `blocking-foreign-key`, `table-rebuild`, `data-mutation`.
- Support for MySQL/MariaDB, PostgreSQL and SQLite on Laravel 12 and 13 (PHP 8.2+, Laravel 13 needs PHP 8.3+).

[1.1.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.1.0
[1.0.4]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.4
[1.0.3]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.3
[1.0.2]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.2
[1.0.1]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.1
[1.0.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.0
