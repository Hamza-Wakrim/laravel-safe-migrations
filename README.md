# Laravel Safe Migrations

[![Tests](https://github.com/Hamza-Wakrim/laravel-safe-migrations/actions/workflows/tests.yml/badge.svg)](https://github.com/Hamza-Wakrim/laravel-safe-migrations/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/hamza-wakrim/laravel-safe-migrations.svg)](https://packagist.org/packages/hamza-wakrim/laravel-safe-migrations)
[![License](https://img.shields.io/github/license/Hamza-Wakrim/laravel-safe-migrations.svg)](LICENSE)

**Find dangerous migrations before they reach production.**

Some migrations look harmless but take your app down when they run on a busy production database:

- dropping or renaming a column that the old code (still running during the deploy) is reading,
- adding a `NOT NULL` column to a table that already has rows,
- building an index on PostgreSQL, which blocks every write to the table until it finishes,
- an `UPDATE` without a `WHERE` that rewrites millions of rows in one transaction.

This package finds those problems **before** you run `migrate`. It dry-runs your pending migrations, looks at the SQL they would execute, and tells you what is risky, why, and how to do it safely.

```
  2024_02_01_000000_drop_legacy_from_users
   ERROR  [drop-column] Dropping column [users.legacy] destroys its data, and app servers still running the old code will fail on queries that use it.
         alter table "users" drop column "legacy"
         → Remove every use of the column from the code and deploy that first, then drop the column in a follow-up migration.

  2024_04_01_000000_backfill_names
   WARN  [data-mutation] Updating [users] without a WHERE clause rewrites every row in one long transaction.
         update "users" set "name" = 'unknown'
         → Move data changes into a queued job or command that works in batches, and keep migrations for schema changes.

   ERROR  Checked 2 migration(s): 1 error(s), 1 warning(s).
```

---

## Contents

1. [How it works](#how-it-works)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick start](#quick-start)
5. [The three ways to use it](#the-three-ways-to-use-it)
   - [A. Check before you merge (CI)](#a-check-before-you-merge-ci)
   - [B. `migrate:safe` in your deploy script](#b-migratesafe-in-your-deploy-script)
   - [C. Guard the plain `migrate` command](#c-guard-the-plain-migrate-command)
6. [Errors vs. warnings](#errors-vs-warnings)
7. [When a finding is intentional](#when-a-finding-is-intentional)
8. [Rules and how to fix each one](#rules-and-how-to-fix-each-one)
9. [Configuration reference](#configuration-reference)
10. [Command reference](#command-reference)
11. [JSON output](#json-output)
12. [Limitations](#limitations)
13. [Troubleshooting](#troubleshooting)
14. [Developing this package](#developing-this-package)

---

## How it works

1. It finds the migrations that have **not run yet** on the database you point it at (the same list `php artisan migrate:status` shows as *Pending*).
2. It runs each one in Laravel's **pretend mode**. Nothing is written to the database; Laravel only records the SQL it *would* run.
3. Each SQL statement is checked against a set of [rules](#rules-and-how-to-fix-each-one).
4. Every problem is reported with the migration name, the SQL, an explanation and a suggested fix.

Because it reads the SQL and not your PHP code, it also catches `DB::statement(...)`, `DB::table(...)->update(...)` and anything else your migration executes.

Tables that are **created in the same batch** of pending migrations are never flagged. They hold no production data yet, so adding indexes or dropping columns on them is harmless.

## Requirements

- PHP 8.2 or newer
- Laravel 11 or newer
- MySQL / MariaDB, PostgreSQL or SQLite (SQL Server is not supported)

## Installation

### From Packagist

```bash
composer require hamza-wakrim/laravel-safe-migrations
```

### From a local folder (for development)

In your Laravel app's `composer.json`, add a path repository pointing at this folder:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-safe-migrations"
    }
]
```

Then install it:

```bash
composer require hamza-wakrim/laravel-safe-migrations:@dev
```

### Publish the config (optional)

The service provider registers itself automatically. Publish the config file only if you want to change rules or turn on the guard:

```bash
php artisan vendor:publish --tag=safe-migrations-config
```

This creates `config/safe-migrations.php`.

## Quick start

1. Install the package (see above).
2. Create a migration that drops a column:

   ```bash
   php artisan make:migration drop_nickname_from_users
   ```

   ```php
   public function up(): void
   {
       Schema::table('users', function (Blueprint $table) {
           $table->dropColumn('nickname');
       });
   }
   ```

3. Check it:

   ```bash
   php artisan safe-migrations:check
   ```

   You get an `ERROR [drop-column]` with an explanation and the command exits with code `1`.

4. Decide what to do:
   - **Fix it**: follow the suggestion (for example, ship the code that stops using the column first, drop it in a later release).
   - **Accept it**: you have checked that nothing uses the column any more, so mark the migration with [`#[AllowUnsafe]`](#when-a-finding-is-intentional).
   - **Run it once anyway**: `php artisan migrate:safe --unsafe`.

## The three ways to use it

Use one, two or all three. Most teams use **A** and **B** together.

### A. Check before you merge (CI)

Run the check on every pull request so unsafe migrations are caught in review.

```bash
php artisan safe-migrations:check
```

It exits with `0` when everything is safe and `1` when there are errors, so a failing check fails the CI job.

> **Important:** the check only looks at migrations that have *not run yet*. In CI, the database must already have your **existing** migrations applied, so that only the new migrations from the pull request are checked. If you check against an empty database, every table is "created in this run" and almost nothing gets flagged.

The usual way to do that is to migrate the database with your main branch first, then check the pull request:

```yaml
# .github/workflows/safe-migrations.yml (example)
name: Safe migrations

on: pull_request

jobs:
  check:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:17
        env:
          POSTGRES_DB: app
          POSTGRES_USER: app
          POSTGRES_PASSWORD: secret
        ports: ["5432:5432"]
        options: --health-cmd pg_isready --health-interval 5s --health-retries 10
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_DATABASE: app
      DB_USERNAME: app
      DB_PASSWORD: secret
    steps:
      # 1. Apply the migrations that are already on the main branch.
      - uses: actions/checkout@v4
        with:
          ref: ${{ github.base_ref }}
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
      - run: cp .env.example .env && composer install --no-interaction && php artisan key:generate
      - run: php artisan migrate --force

      # 2. Switch to the pull request and check only its new migrations.
      - uses: actions/checkout@v4
        with:
          clean: false
      - run: composer install --no-interaction
      - run: php artisan safe-migrations:check
```

Use the **same database engine as production** in CI (PostgreSQL if you run PostgreSQL). Several rules only apply to one engine because the locking behaviour is different.

### B. `migrate:safe` in your deploy script

Replace `php artisan migrate --force` in your deploy script (Forge, Envoyer, Deployer, a shell script...) with:

```bash
php artisan migrate:safe --force
```

It checks the pending migrations against the real production database first:

- **Nothing unsafe:** it runs `migrate` as usual.
- **Errors found:** it prints the report, does **not** migrate, and exits with `1`, which stops the deploy.

`migrate:safe` accepts the usual `migrate` options (`--database`, `--path`, `--realpath`, `--force`, `--step`, `--seed`) plus:

| Option | Effect |
| --- | --- |
| `--strict` | Also refuse to migrate when there are only warnings |
| `--unsafe` | Print the report but migrate anyway. Use this for a one-off run after you reviewed the findings |

### C. Guard the plain `migrate` command

If people or tools run `php artisan migrate` directly, you can make that command refuse unsafe migrations too.

In `.env` on the server:

```dotenv
SAFE_MIGRATIONS_GUARD=true
```

Now in the environments listed in `guard.environments` (default: `production`), `php artisan migrate` stops before running anything if a pending migration has errors:

```
Refusing to migrate: 1 unsafe operation(s) found in pending migrations.
  - [drop-column] 2024_02_01_000000_drop_legacy_from_users: Dropping column [users.legacy] ...
```

Notes:

- `migrate --pretend` is never blocked.
- `migrate:safe --unsafe` still works with the guard on, so that is the way to run a reviewed migration once.
- To switch the guard off without a deploy, set `SAFE_MIGRATIONS_ALLOW_UNSAFE=true` in `.env` and run `php artisan config:clear` (or `config:cache`). Remember to remove it again.

## Errors vs. warnings

| | Meaning | Blocks `check` / `migrate:safe` / guard? |
| --- | --- | --- |
| **ERROR** | Almost always breaks something: lost data, failing queries from the running code, or a migration that fails on a non-empty table. | Yes |
| **WARN** | Can lock a table or be slow, depending on table size and database version. Worth a look. | Only with `--strict` (or `guard.strict`) |

You can change the severity of any rule, or switch it off, in the [config](#configuration-reference).

## When a finding is intentional

Sometimes you really do want to drop that column. Mark the migration with the `AllowUnsafe` attribute so the decision is written down next to the code and visible in code review:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use HamzaWakrim\SafeMigrations\Attributes\AllowUnsafe;

return new #[AllowUnsafe('drop-column', reason: 'nickname has not been used since v2.3')] class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
```

- Allow several rules: `#[AllowUnsafe(['drop-column', 'drop-table'])]`
- Allow everything in this migration: `#[AllowUnsafe]`
- Named (non-anonymous) migration classes work the same way: put the attribute above `class ... extends Migration`.

Allowed findings don't fail the check. The summary still counts them (`1 allowed by #[AllowUnsafe]`), and they appear under `suppressed` in the [JSON output](#json-output).

To skip a migration completely (for example, an old one you don't want to touch), add its file name without `.php` to `ignore_migrations` in the config.

## Rules and how to fix each one

| Rule | Default | Databases |
| --- | --- | --- |
| [`drop-table`](#drop-table) | error | all |
| [`drop-column`](#drop-column) | error | all |
| [`rename-table`](#rename-table-and-rename-column) | error | all |
| [`rename-column`](#rename-table-and-rename-column) | error | all |
| [`add-not-null-column`](#add-not-null-column) | error (warning on MySQL) | all |
| [`change-column`](#change-column) | warning | MySQL, PostgreSQL |
| [`set-not-null`](#set-not-null) | warning | PostgreSQL |
| [`blocking-index`](#blocking-index) | warning | PostgreSQL |
| [`blocking-foreign-key`](#blocking-foreign-key) | warning | PostgreSQL |
| [`table-rebuild`](#table-rebuild) | warning | SQLite |
| [`data-mutation`](#data-mutation) | warning | all |

Most fixes follow the same idea, sometimes called **expand / contract**: never remove or change something the running code depends on in the same deploy. Add the new thing, deploy code that uses it, and remove the old thing in a later release.

### `drop-table`

**Flags:** `Schema::drop()` / `Schema::dropIfExists()` on an existing table.

**Why:** the data is gone, and during the deploy the old code is still running and querying the table.

**Safe way:**
1. Deploy code that no longer reads or writes the table.
2. Back up the table if you might need the data.
3. Drop it in a migration in a later release, marked with `#[AllowUnsafe('drop-table', reason: '...')]`.

### `drop-column`

**Flags:** `$table->dropColumn(...)` on an existing table.

**Why:** Eloquent's `select *` and any code still using the column fail between the moment the migration runs and the moment the new code is live.

**Safe way:**
1. Remove every use of the column from the code (models, queries, validation, API resources) and deploy.
2. In the next release, drop the column with `#[AllowUnsafe('drop-column', reason: '...')]`.

### `rename-table` and `rename-column`

**Flags:** `Schema::rename()`, `$table->renameColumn()`.

**Why:** the code that is running during the deploy still uses the old name.

**Safe way (for a column):**
1. Add the new column (nullable).
2. Write to both columns in the code; backfill the new column in batches.
3. Switch reads to the new column and deploy.
4. Drop the old column in a later release.

Or keep the database name and change only the PHP side (an Eloquent accessor, or an API resource field name).

### `add-not-null-column`

**Flags:** adding a column that is `NOT NULL` and has no default.

```php
$table->string('timezone');  // flagged: NOT NULL, no default
```

**Why:** PostgreSQL and SQLite refuse to add it as soon as the table has rows, so the migration fails in production even though it worked on your empty local database. MySQL adds it but silently fills existing rows with `''`, `0` or a zero date, which is why this rule is only a warning on MySQL.

**Safe way:** either give it a default:

```php
$table->string('timezone')->default('UTC');
```

or do it in steps:

```php
// Migration 1
$table->string('timezone')->nullable();

// Backfill the existing rows (a job or command, in batches)

// Migration 2 (later release)
$table->string('timezone')->nullable(false)->change();
```

On PostgreSQL, migration 2 is reported as [`change-column`](#change-column) and [`set-not-null`](#set-not-null) warnings. See `set-not-null` for the lock-free version.

### `change-column`

**Flags:** `->change()` and other column type changes (`MODIFY` / `CHANGE` on MySQL, `ALTER COLUMN ... TYPE` on PostgreSQL).

**Why:** many type changes make the database rewrite the whole table while holding a lock, blocking reads and writes. On a big table that can take minutes.

**Safe way:** check your database's documentation for that specific change (some, such as making a `varchar` longer on PostgreSQL, are instant). For a big table, add a new column, backfill it in batches and swap. If you have checked that it is fine, add `#[AllowUnsafe('change-column')]`.

### `set-not-null`

**Flags (PostgreSQL):** making an existing column `NOT NULL`, for example `->nullable(false)->change()`.

**Why:** PostgreSQL scans the whole table to make sure there are no NULLs, under a lock that blocks reads and writes.

**Safe way (PostgreSQL 12+):** prove there are no NULLs first with a constraint that is validated without the heavy lock:

```php
// Migration 1
DB::statement('ALTER TABLE users ADD CONSTRAINT users_timezone_not_null CHECK (timezone IS NOT NULL) NOT VALID');

// Migration 2
DB::statement('ALTER TABLE users VALIDATE CONSTRAINT users_timezone_not_null');

// Migration 3: PostgreSQL uses the validated constraint and skips the scan
DB::statement('ALTER TABLE users ALTER COLUMN timezone SET NOT NULL');
DB::statement('ALTER TABLE users DROP CONSTRAINT users_timezone_not_null');
```

The checker cannot tell that step 3 is now cheap, so mark migration 3 with `#[AllowUnsafe('set-not-null')]`.

### `blocking-index`

**Flags (PostgreSQL):** `$table->index()`, `$table->unique()` and `$table->primary()` on an existing table.

**Why:** a normal `CREATE INDEX` blocks every `INSERT`, `UPDATE` and `DELETE` on the table until the index is built.

**Safe way:** build it with `CONCURRENTLY`. That cannot run inside a transaction, so turn the migration's transaction off:

```php
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY users_email_index ON users (email)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_email_index');
    }
};
```

For a unique constraint, build the unique index concurrently, then attach it:

```php
DB::statement('CREATE UNIQUE INDEX CONCURRENTLY users_email_unique ON users (email)');
DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE USING INDEX users_email_unique');
```

On MySQL (InnoDB), adding a normal index is an online operation, so this rule does not apply.

### `blocking-foreign-key`

**Flags (PostgreSQL):** `$table->foreign(...)` / `->constrained()` on an existing table.

**Why:** PostgreSQL checks every existing row while blocking writes on **both** tables.

**Safe way:** add the constraint without checking old rows, then validate it separately (validation only takes a light lock):

```php
// Migration 1
DB::statement('ALTER TABLE posts ADD CONSTRAINT posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) NOT VALID');

// Migration 2
DB::statement('ALTER TABLE posts VALIDATE CONSTRAINT posts_user_id_foreign');
```

### `table-rebuild`

**Flags (SQLite):** operations SQLite cannot do in place (such as `->change()`), which Laravel performs by copying the whole table into a new one.

**Why:** the database is locked while every row is copied. That's fine for small tables and slow for big ones.

**Safe way:** usually nothing. Just be aware of it on large SQLite databases.

### `data-mutation`

**Flags:** `UPDATE` or `DELETE` without a `WHERE` clause, and `TRUNCATE`, on existing tables.

```php
DB::table('users')->update(['newsletter' => false]);  // flagged
```

**Why:** one statement that touches every row holds locks for a long time, bloats the transaction log, and can time out your deploy.

**Safe way:** move data changes out of the migration into a command or queued job that works in chunks:

```php
User::query()->whereNull('newsletter')->chunkById(1000, function ($users) {
    User::whereKey($users->modelKeys())->update(['newsletter' => false]);
});
```

## Configuration reference

After publishing, `config/safe-migrations.php` contains:

```php
return [
    // null = the rule's default severity, 'error' / 'warning' = override, false = rule off
    'rules' => [
        'drop-table' => null,
        'drop-column' => null,
        'rename-table' => null,
        'rename-column' => null,
        'add-not-null-column' => null,
        'change-column' => null,
        'set-not-null' => null,
        'blocking-index' => null,
        'blocking-foreign-key' => null,
        'table-rebuild' => null,
        'data-mutation' => null,
    ],

    // Migration names (file name without .php) that are never checked
    'ignore_migrations' => [],

    'guard' => [
        'enabled' => env('SAFE_MIGRATIONS_GUARD', false),         // guard `php artisan migrate`
        'environments' => ['production'],                          // where the guard is active
        'strict' => env('SAFE_MIGRATIONS_STRICT', false),          // block on warnings too
        'allow_unsafe' => env('SAFE_MIGRATIONS_ALLOW_UNSAFE', false), // temporarily switch the guard off
    ],
];
```

Examples:

```php
// Treat column changes as errors, and stop reporting data updates
'rules' => [
    'change-column' => 'error',
    'data-mutation' => false,
],

// Guard staging as well as production
'guard' => [
    'enabled' => env('SAFE_MIGRATIONS_GUARD', false),
    'environments' => ['production', 'staging'],
    // ...
],
```

| Environment variable | Default | Effect |
| --- | --- | --- |
| `SAFE_MIGRATIONS_GUARD` | `false` | Turn the `migrate` guard on |
| `SAFE_MIGRATIONS_STRICT` | `false` | Guard blocks on warnings too |
| `SAFE_MIGRATIONS_ALLOW_UNSAFE` | `false` | Switch the guard off temporarily |

These are read through the config, so after changing them on a server with cached config, run `php artisan config:cache` again.

## Command reference

### `php artisan safe-migrations:check`

Reports unsafe operations in pending migrations. Never changes the database.

| Option | Description |
| --- | --- |
| `--database=NAME` | Database connection to check against. Its driver decides which rules apply. Default: your default connection |
| `--path=PATH` | Check migrations in this path instead of the default ones (can be repeated) |
| `--realpath` | `--path` values are absolute paths |
| `--all` | Check every migration, including ones that already ran |
| `--strict` | Exit with `1` on warnings too |
| `--format=json` | Print [JSON](#json-output) instead of the readable report |

Exit codes: `0` = safe, `1` = unsafe (errors, or warnings with `--strict`).

Examples:

```bash
# Check against a specific connection
php artisan safe-migrations:check --database=pgsql

# Check one module's migrations
php artisan safe-migrations:check --path=modules/Billing/database/migrations

# Fail on warnings as well
php artisan safe-migrations:check --strict
```

### `php artisan migrate:safe`

Runs the check, then `migrate` if it passes.

| Option | Description |
| --- | --- |
| `--database`, `--path`, `--realpath`, `--force`, `--step`, `--seed` | Passed on to `migrate` |
| `--strict` | Refuse to migrate on warnings too |
| `--unsafe` | Migrate even if unsafe operations are found |

## JSON output

`php artisan safe-migrations:check --format=json` prints:

```json
{
    "migrations": [
        "2024_02_01_000000_drop_legacy_from_users"
    ],
    "errors": 1,
    "warnings": 0,
    "findings": [
        {
            "migration": "2024_02_01_000000_drop_legacy_from_users",
            "rule": "drop-column",
            "severity": "error",
            "subject": "users.legacy",
            "message": "Dropping column [users.legacy] destroys its data, and app servers still running the old code will fail on queries that use it.",
            "suggestion": "Remove every use of the column from the code and deploy that first, then drop the column in a follow-up migration.",
            "sql": "alter table \"users\" drop column \"legacy\""
        }
    ],
    "suppressed": []
}
```

- `migrations`: the pending migrations that were checked.
- `findings`: problems that count towards the result.
- `suppressed`: problems allowed by `#[AllowUnsafe]`, same shape as `findings`.

Use it to post a comment on a pull request or feed another tool.

## Limitations

- **Pretend mode returns no data.** During the dry run, `select` queries return nothing. A migration that does `if (Schema::hasColumn(...))` or reads rows to decide what to do is checked along the path it takes when those checks come back empty. If the dry run throws an exception, the migration is reported as an `analysis-failed` warning so you can review it by hand.
- **It can't know your table sizes.** Warnings describe what *can* lock; on a table with 200 rows it doesn't matter. Use your judgement, and `#[AllowUnsafe]` once you've checked.
- **Only `up()` is checked**, not `down()`.
- **SQL Server is not supported.**
- The guard reads only the first `--path` given to `migrate`.

## Troubleshooting

**"Nothing to check: no pending migrations" but I just created one.**
The check only looks at migrations that haven't run. If you already ran it locally, roll back (`php artisan migrate:rollback`) or use `--all`.

**Nothing is flagged in CI, but it should be.**
The CI database is probably empty, so every table counts as new. Migrate it with your main branch first (see [the CI example](#a-check-before-you-merge-ci)).

**A rule fires for something I know is safe.**
Mark that migration with `#[AllowUnsafe('rule-name', reason: '...')]`. If it's never relevant for your project, turn the rule off in the config.

**PostgreSQL rules don't fire.**
They only apply when the checked connection uses the `pgsql` driver. Run the check against a PostgreSQL connection (`--database=pgsql`), not your local SQLite.

**The guard doesn't block `migrate`.**
Check that `SAFE_MIGRATIONS_GUARD=true`, that `APP_ENV` is listed in `guard.environments`, that `SAFE_MIGRATIONS_ALLOW_UNSAFE` isn't set, and run `php artisan config:cache` again if config is cached.

## Developing this package

Everything runs in Docker, including MySQL 8.4 and PostgreSQL 17 for the integration tests:

```bash
docker compose build
docker compose run --rm tests
docker compose down
```

- `tests/Unit` checks the rules against hand-written SQL.
- `tests/Feature/GrammarIntegrationTest.php` runs real Laravel schema code through each database's grammar, so the rules are tested against the exact SQL Laravel generates on SQLite, MySQL and PostgreSQL.
- `tests/Feature/CommandsTest.php` covers the commands, `#[AllowUnsafe]` and the guard.

Without Docker, `composer install && vendor/bin/phpunit` runs the SQLite tests and skips MySQL and PostgreSQL.

To add a rule: implement `HamzaWakrim\SafeMigrations\Rules\Rule`, add it to `Analyzer::defaultRules()`, add its id to `config/safe-migrations.php`, and add cases to both test files.

## License

MIT. See [LICENSE](LICENSE).
