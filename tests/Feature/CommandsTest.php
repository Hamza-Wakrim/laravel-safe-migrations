<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use HamzaWakrim\SafeMigrations\Exceptions\UnsafeMigrationException;
use HamzaWakrim\SafeMigrations\Tests\TestCase;

final class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('legacy')->nullable();
        });

        // A real `php artisan` run forwards Symfony's console events as CommandStarting;
        // Laravel skips that under unit tests, so switch it on for the guard tests.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }

    public function test_check_fails_on_unsafe_pending_migration(): void
    {
        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('unsafe'), '--realpath' => true])
            ->expectsOutputToContain('[drop-column] Dropping column [users.legacy]')
            ->assertFailed();
    }

    public function test_check_passes_for_safe_migrations(): void
    {
        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('safe'), '--realpath' => true])
            ->expectsOutputToContain('0 error(s), 0 warning(s)')
            ->assertSuccessful();
    }

    public function test_allow_unsafe_attribute_suppresses_findings(): void
    {
        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('allowed'), '--realpath' => true])
            ->expectsOutputToContain('1 allowed by #[AllowUnsafe]')
            ->assertSuccessful();
    }

    public function test_strict_mode_fails_on_warnings(): void
    {
        $args = ['--path' => $this->fixture('warning'), '--realpath' => true];

        $this->artisan('safe-migrations:check', $args)->assertSuccessful();
        $this->artisan('safe-migrations:check', $args + ['--strict' => true])->assertFailed();
    }

    public function test_json_output(): void
    {
        $exit = Artisan::call('safe-migrations:check', ['--path' => $this->fixture('unsafe'), '--realpath' => true, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame(1, $report['errors']);
        $this->assertSame('drop-column', $report['findings'][0]['rule']);
        $this->assertSame('2024_02_01_000000_drop_legacy_from_users', $report['findings'][0]['migration']);
    }

    public function test_ignored_migrations_are_skipped(): void
    {
        config(['safe-migrations.ignore_migrations' => ['2024_02_01_000000_drop_legacy_from_users']]);

        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('unsafe'), '--realpath' => true])->assertSuccessful();
    }

    public function test_migrate_safe_blocks_unsafe_migrations(): void
    {
        $this->artisan('migrate:safe', ['--path' => $this->fixture('unsafe'), '--realpath' => true])
            ->expectsOutputToContain('Migrations not run')
            ->assertFailed();

        $this->assertTrue(Schema::hasColumn('users', 'legacy'));
    }

    public function test_migrate_safe_runs_with_unsafe_flag_and_then_nothing_is_pending(): void
    {
        $args = ['--path' => $this->fixture('unsafe'), '--realpath' => true];

        $this->artisan('migrate:safe', $args + ['--unsafe' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('users', 'legacy'));

        $this->artisan('safe-migrations:check', $args)
            ->expectsOutputToContain('no pending migrations')
            ->assertSuccessful();

        $this->artisan('safe-migrations:check', $args + ['--all' => true])->assertFailed();
    }

    public function test_migrate_safe_runs_safe_migrations(): void
    {
        $this->artisan('migrate:safe', ['--path' => $this->fixture('safe'), '--realpath' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('teams'));
        $this->assertTrue(Schema::hasColumn('users', 'nickname'));
    }

    public function test_guard_blocks_plain_migrate_command(): void
    {
        config(['safe-migrations.guard.enabled' => true, 'safe-migrations.guard.environments' => ['testing']]);

        try {
            Artisan::call('migrate', ['--path' => $this->fixture('unsafe'), '--realpath' => true]);
            $this->fail('The guard should have stopped the migration.');
        } catch (UnsafeMigrationException $e) {
            $this->assertStringContainsString('[drop-column]', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'legacy'));
    }

    public function test_guard_can_be_bypassed(): void
    {
        config([
            'safe-migrations.guard.enabled' => true,
            'safe-migrations.guard.environments' => ['testing'],
            'safe-migrations.guard.allow_unsafe' => true,
        ]);

        Artisan::call('migrate', ['--path' => $this->fixture('unsafe'), '--realpath' => true]);

        $this->assertFalse(Schema::hasColumn('users', 'legacy'));
    }

    public function test_check_never_touches_other_connections_used_by_a_migration(): void
    {
        $secondary = Schema::connection('secondary');
        $secondary->create('events', function (Blueprint $t) {
            $t->id();
            $t->string('legacy')->nullable();
        });

        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('multi'), '--realpath' => true])
            ->expectsOutputToContain('[drop-column] Dropping column [events.legacy]')
            ->assertFailed();

        $this->assertFalse($secondary->hasTable('audit_log'), 'The dry run created a table on the secondary connection.');
        $this->assertTrue($secondary->hasColumn('events', 'legacy'), 'The dry run dropped a column on the secondary connection.');
        $this->assertFalse(Schema::hasTable('teams'));
    }

    public function test_a_migration_that_fails_in_the_dry_run_is_reported_as_a_warning(): void
    {
        $args = ['--path' => $this->fixture('failing'), '--realpath' => true];

        $this->artisan('safe-migrations:check', $args)
            ->expectsOutputToContain('[analysis-failed] Could not dry-run this migration')
            ->assertSuccessful();

        $this->artisan('safe-migrations:check', $args + ['--strict' => true])->assertFailed();
    }

    public function test_analysis_failed_can_be_configured_like_other_rules(): void
    {
        $args = ['--path' => $this->fixture('failing'), '--realpath' => true, '--strict' => true];

        config(['safe-migrations.rules.analysis-failed' => false]);
        $this->app->forgetInstance(\HamzaWakrim\SafeMigrations\Analysis\Analyzer::class);
        $this->artisan('safe-migrations:check', $args)->assertSuccessful();

        config(['safe-migrations.rules.analysis-failed' => 'error']);
        $this->app->forgetInstance(\HamzaWakrim\SafeMigrations\Analysis\Analyzer::class);
        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('failing'), '--realpath' => true])->assertFailed();
    }

    public function test_invalid_format_is_rejected(): void
    {
        $this->artisan('safe-migrations:check', ['--path' => $this->fixture('safe'), '--realpath' => true, '--format' => 'xml'])
            ->expectsOutputToContain('Invalid --format')
            ->assertExitCode(2);
    }

    public function test_migrate_safe_unsafe_still_works_with_guard_on(): void
    {
        config(['safe-migrations.guard.enabled' => true, 'safe-migrations.guard.environments' => ['testing']]);

        $this->artisan('migrate:safe', ['--path' => $this->fixture('unsafe'), '--realpath' => true, '--unsafe' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('users', 'legacy'));
    }

    public function test_guard_ignores_other_environments(): void
    {
        config(['safe-migrations.guard.enabled' => true, 'safe-migrations.guard.environments' => ['production']]);

        Artisan::call('migrate', ['--path' => $this->fixture('unsafe'), '--realpath' => true]);

        $this->assertFalse(Schema::hasColumn('users', 'legacy'));
    }
}
