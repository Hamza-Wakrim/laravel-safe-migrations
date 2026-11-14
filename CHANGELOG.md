# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

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

[1.0.1]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.1
[1.0.0]: https://github.com/Hamza-Wakrim/laravel-safe-migrations/releases/tag/v1.0.0
