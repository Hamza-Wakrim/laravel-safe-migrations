# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.3.1] - 2026-11-27

### Fixed

- **`lock-timeout` on rollback used `up()`'s timeout.** A `SET lock_timeout` / `SET SESSION lock_wait_timeout` in `up()` was counted for `down()` too, but a rollback runs in another session where it isn't set. `down()` now needs its own timeout (reported as `unsafe-rollback` when `lock-timeout` is on).

### Tests

- The integration tests now also run on MariaDB 11.4 (Docker and CI), and cover rollback analysis, `schema-condition`, `lock-timeout` and MariaDB's `WAIT` / `SET STATEMENT` on real MySQL, MariaDB and PostgreSQL servers.

## [1.3.0] - 2026-11-26

### Added

- **Rollback analysis.** `down()` is now dry-run too, on the schema `up()` leaves behind, and checked with the same rules.
  - `unsafe-rollback` (warning): `down()` does something the rules flag on a table or column that existed before `up()`, for example dropping a column `up()` didn't add. Undoing what `up()` added is not reported. The message names the rule that matched: `On rollback (drop-column): ...`.
  - `missing-down` (opt-in): `up()` changes the database but there is no `down()`, or it does nothing. A `down()` that throws is treated as deliberately irreversible and not reported.
- **Source analysis.** The PHP source of `up()` is read (with `nikic/php-parser`, now a dependency) to report what a dry run can't see:
  - `schema-condition` (warning): a branch on `Schema::hasTable()` / `hasColumn()` / `hasColumns()` / `hasIndex()` / `hasView()` whose skipped branch contains code. These checks always answer false during the dry run.
  - `reads-data` (warning): `up()` reads rows (`DB::table(...)->get()`, `first()`, `chunk()`, `DB::select()`, `User::all()`, ...). The dry run gets no rows, so per-row work was never checked.
- **More side effects are faked during the check:** events (listeners don't run), cache (in-memory store), storage (a temporary folder, deleted afterwards), processes (`Process::`) and `Artisan::call()`. The real services, and the Eloquent model event dispatcher, are put back afterwards.

## [1.2.0] - 2026-11-26

### Added

- **`lock-timeout` rule (MySQL, MariaDB, PostgreSQL, opt-in).** Flags `ALTER TABLE`, `CREATE INDEX` (without `CONCURRENTLY`) and `RENAME TABLE` on an existing table when the migration did not set a lock timeout first. Even an instant `ALTER` waits for a lock, and every query after it queues behind it, so a single slow query can block the table. Passes after `SET lock_timeout` (PostgreSQL), `SET SESSION lock_wait_timeout` (MySQL, MariaDB), or MariaDB's `WAIT n` / `NOWAIT` / `SET STATEMENT lock_wait_timeout=n FOR ...`. Off by default because Laravel never sets a timeout; turn it on with `'lock-timeout' => 'warning'`.
- Rules can be opt-in (`OptInRule`): they only run when the config gives them a severity.

### Fixed

- **MariaDB `WAIT n` / `NOWAIT` and `SET STATEMENT ... FOR` hid statements from the rules.** `ALTER TABLE users WAIT 5 DROP COLUMN bio` and `SET STATEMENT lock_wait_timeout=5 FOR ALTER TABLE users DROP COLUMN bio` were not flagged by `drop-column` or any other rule. Both are now read as the statement they wrap.

## [1.1.1] - 2026-11-25

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

[1.3.1]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.3.1
[1.3.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.3.0
[1.2.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.2.0
[1.1.1]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.1.1
[1.1.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.1.0
[1.0.4]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.4
[1.0.3]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.3
[1.0.2]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.2
[1.0.1]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.1
[1.0.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.0
