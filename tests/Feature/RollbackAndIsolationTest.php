<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class RollbackAndIsolationTest extends TestCase
{
    public static bool $commandRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('legacy')->nullable();
        });

        self::$commandRan = false;
    }

    /** @return array<string, mixed> */
    private function check(string $fixture): array
    {
        $this->app->forgetInstance(Analyzer::class);
        Artisan::call('safe-migrations:check', ['--path' => $this->fixture($fixture), '--realpath' => true, '--format' => 'json']);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_down_that_drops_a_column_up_did_not_add_is_reported(): void
    {
        $findings = $this->check('rollback')['findings'];

        $this->assertSame(['unsafe-rollback'], array_column($findings, 'rule'));
        $this->assertSame('drop-column:users.legacy', $findings[0]['subject']);
        $this->assertSame('2024_10_02_000000_add_nickname_to_users', $findings[0]['migration']);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertStringStartsWith('On rollback (drop-column):', $findings[0]['message']);
    }

    public function test_rollback_analysis_can_be_turned_off(): void
    {
        config(['safe-migrations.rules.unsafe-rollback' => false]);

        $this->assertSame([], $this->check('rollback')['findings']);
    }

    public function test_missing_down_is_opt_in(): void
    {
        $this->assertSame([], $this->check('missing-down')['findings']);

        config(['safe-migrations.rules.missing-down' => 'warning']);

        $this->assertSame(['missing-down'], array_column($this->check('missing-down')['findings'], 'rule'));
    }

    public function test_storage_cache_events_processes_and_artisan_are_faked_during_the_check(): void
    {
        $marker = sys_get_temp_dir().'/safe-migrations-process-ran';
        @unlink($marker);
        Storage::disk('local')->delete('safe-migrations-check.txt');

        $heard = false;
        Event::listen('safe-migrations.migrating', function () use (&$heard) {
            $heard = true;
        });
        Artisan::command('safe-migrations:test-touch', fn () => RollbackAndIsolationTest::$commandRan = true);

        $roots = [Storage::getFacadeRoot(), Cache::getFacadeRoot(), Event::getFacadeRoot(), Process::getFacadeRoot(), Artisan::getFacadeRoot()];

        $this->assertSame([], $this->check('isolation')['findings']);

        $this->assertFalse(Storage::disk('local')->exists('safe-migrations-check.txt'));
        $this->assertNull(Cache::get('safe-migrations-check'));
        $this->assertNull(Cache::store('array')->get('safe-migrations-helper'));
        $this->assertFalse($heard);
        $this->assertFileDoesNotExist($marker);
        $this->assertFalse(self::$commandRan);

        // The real services are back for the `migrate` that may follow.
        $this->assertSame($roots, [Storage::getFacadeRoot(), Cache::getFacadeRoot(), Event::getFacadeRoot(), Process::getFacadeRoot(), Artisan::getFacadeRoot()]);
        $this->assertSame($roots[1], $this->app->make('cache'));
        event('safe-migrations.migrating');
        $this->assertTrue($heard);
    }
}
