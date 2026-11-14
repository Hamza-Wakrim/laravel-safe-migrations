<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use HamzaWakrim\SafeMigrations\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the artisan commands end to end against real MySQL and PostgreSQL
 * servers (docker-compose.yml / CI). Skipped when they are not configured.
 */
final class RealDatabaseCommandsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function connections(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
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
