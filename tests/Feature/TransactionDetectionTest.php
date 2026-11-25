<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Statement;
use HamzaWakrim\SafeMigrations\MigrationInspector;
use HamzaWakrim\SafeMigrations\Rules\Rule;
use HamzaWakrim\SafeMigrations\Tests\TestCase;

/**
 * The inspector must tell rules whether Laravel will run each statement inside
 * the migration's transaction, the same way Migrator::runMigration() decides.
 */
final class TransactionDetectionTest extends TestCase
{
    public function test_statements_know_whether_they_run_inside_the_migration_transaction(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        $spy = new class implements Rule
        {
            /** @var list<array{?string, string, bool}> */
            public array $seen = [];

            public function id(): string
            {
                return 'spy';
            }

            public function inspect(Statement $statement, Context $context): iterable
            {
                $this->seen[] = [$statement->migration, $statement->driver, $statement->inTransaction];

                return [];
            }
        };

        $this->app->instance(Analyzer::class, new Analyzer([$spy]));

        // Dry runs never open a connection, so no PostgreSQL server is needed here.
        $this->app->make(MigrationInspector::class)->inspect([$this->fixture('transaction')]);

        $this->assertSame([
            // Laravel wraps a PostgreSQL migration in a transaction, and DB:: goes to the migration's connection...
            ['2024_08_01_000000_postgres_in_transaction', 'pgsql', true],
            // ...but statements sent to another connection are outside it.
            ['2024_08_01_000000_postgres_in_transaction', 'sqlite', false],
            // $withinTransaction = false turns the transaction off.
            ['2024_08_02_000000_postgres_without_transaction', 'pgsql', false],
            // Laravel never wraps SQLite (or MySQL) migrations in a transaction.
            ['2024_08_03_000000_sqlite_has_no_migration_transaction', 'sqlite', false],
        ], $spy->seen);
    }

    public function test_check_reports_concurrently_inside_a_transactional_migration(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        Artisan::call('safe-migrations:check', ['--path' => $this->fixture('transaction'), '--realpath' => true, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            [['concurrent-in-transaction', '2024_08_01_000000_postgres_in_transaction', 'error']],
            array_map(fn (array $f) => [$f['rule'], $f['migration'], $f['severity']], $report['findings']),
        );
    }
}
