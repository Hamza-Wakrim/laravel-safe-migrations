<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use HamzaWakrim\SafeMigrations\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the artisan commands end to end against real MySQL, MariaDB and PostgreSQL
 * servers (docker-compose.yml / CI). Skipped when they are not configured.
 */
final class RealDatabaseCommandsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function connections(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('connections')]
    public function test_check_and_migrate_safe_on_a_real_database(string $connection): void
    {
        $this->useConnection($connection);
        $args = ['--path' => $this->fixture('unsafe'), '--realpath' => true];

        $this->artisan('safe-migrations:check', $args)
            ->expectsOutputToContain('[drop-column] Dropping column [users.legacy]')
            ->assertFailed();

        $this->artisan('migrate:safe', $args)->assertFailed();
        $this->assertTrue(Schema::hasColumn('users', 'legacy'), 'migrate:safe ran despite the error.');

        $this->artisan('migrate:safe', $args + ['--unsafe' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('users', 'legacy'));

        $this->artisan('safe-migrations:check', $args)
            ->expectsOutputToContain('no pending migrations')
            ->assertSuccessful();
    }

    #[DataProvider('connections')]
    public function test_check_does_not_touch_the_database_on_a_real_server(string $connection): void
    {
        $this->useConnection($connection);

        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('safe'), '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('teams'), 'The dry run created a table.');
        $this->assertFalse(Schema::hasColumn('users', 'nickname'), 'The dry run added a column.');
        $this->assertFalse(Schema::hasTable('migrations'), 'The dry run created the migrations table.');
    }

    #[DataProvider('connections')]
    public function test_raw_sql_is_checked_and_pdo_is_guarded_on_a_real_server(string $connection): void
    {
        $this->useConnection($connection);

        foreach (['raw-statement' => 'drop-column', 'rename' => 'rename-column', 'raw-pdo' => 'analysis-failed'] as $fixture => $rule) {
            Artisan::call('safe-migrations:check', ['--path' => $this->fixture($fixture), '--realpath' => true, '--format' => 'json']);
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([$rule], array_column($report['findings'], 'rule'), $fixture);
        }

        $this->assertTrue(Schema::hasColumn('users', 'legacy'), 'The dry run changed the database.');
        $this->assertTrue(Schema::hasColumn('users', 'name'), 'The dry run renamed a column.');
    }

    #[DataProvider('connections')]
    public function test_rollback_analysis_on_a_real_server(string $connection): void
    {
        $this->useConnection($connection);

        $findings = $this->findings('rollback');

        $this->assertSame(['unsafe-rollback'], array_column($findings, 'rule'));
        $this->assertSame('drop-column:users.legacy', $findings[0]['subject']);
        $this->assertTrue(Schema::hasColumn('users', 'legacy'), 'The dry run of down() changed the database.');
    }

    #[DataProvider('connections')]
    public function test_schema_checks_answer_false_in_the_dry_run_on_a_real_server(string $connection): void
    {
        $this->useConnection($connection);

        // [legacy] exists, so `migrate` would drop it, but the dry run skips that branch:
        // only the source check reports it.
        $this->assertSame(['schema-condition'], array_column($this->findings('schema-branch'), 'rule'));
        $this->assertTrue(Schema::hasColumn('users', 'legacy'));
    }

    #[DataProvider('connections')]
    public function test_lock_timeout_on_a_real_server(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame([], $this->findings('lock-timeout'), 'lock-timeout must be off by default.');

        config(['safe-migrations.rules.lock-timeout' => 'warning']);
        $this->app->forgetInstance(\HamzaWakrim\SafeMigrations\Analysis\Analyzer::class);

        $findings = $this->findings('lock-timeout');

        $this->assertSame([
            ['unsafe-rollback', '2024_10_06_000000_add_bio_with_timeout'],      // down() needs its own timeout: a rollback is another session
            ['lock-timeout', '2024_10_07_000000_add_nickname_without_timeout'],
            ['unsafe-rollback', '2024_10_07_000000_add_nickname_without_timeout'],
        ], array_map(fn (array $f) => [$f['rule'], $f['migration']], $findings));
        $this->assertSame('lock-timeout:users', $findings[0]['subject']);
    }

    public function test_mariadb_wait_clause_and_set_statement_on_a_real_server(): void
    {
        $this->useConnection('mariadb');
        config(['safe-migrations.rules.lock-timeout' => 'warning']);
        $this->app->forgetInstance(\HamzaWakrim\SafeMigrations\Analysis\Analyzer::class);

        $findings = $this->findings('mariadb-wait');

        // WAIT n passes lock-timeout but the dropped column is still found; SET STATEMENT is read through.
        $this->assertSame(['drop-column', 'drop-column'], array_column($findings, 'rule'));
        $this->assertTrue(Schema::hasColumn('users', 'legacy'));
        $this->assertTrue(Schema::hasColumn('users', 'name'));
    }

    /** @return list<array<string, string|null>> */
    private function findings(string $fixture): array
    {
        Artisan::call('safe-migrations:check', ['--path' => $this->fixture($fixture), '--realpath' => true, '--format' => 'json']);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['findings'];
    }

    private function useConnection(string $connection): void
    {
        if (! env('SAFE_MIGRATIONS_'.strtoupper($connection).'_HOST')) {
            $this->markTestSkipped('Set SAFE_MIGRATIONS_'.strtoupper($connection).'_HOST (or run via docker compose) to test '.$connection.'.');
        }

        config(['database.default' => $connection]);

        $schema = Schema::connection($connection);

        foreach (['migrations', 'teams', 'users'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('legacy')->nullable();
        });
    }
}
