<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use HamzaWakrim\SafeMigrations\Tests\TestCase;

/**
 * Pretend mode only stops SQL. HTTP calls, mail, notifications and jobs in
 * up() must be faked during the check, and the real services put back after.
 */
final class SideEffectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('legacy')->nullable();
        });
    }

    public function test_side_effects_in_up_do_not_happen_during_the_check(): void
    {
        // The app's own fakes stand in for the real services: if the check
        // used them (instead of its own fakes), they would record something.
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Bus::fake();

        $http = Http::getFacadeRoot();
        $mail = Mail::getFacadeRoot();
        $notifications = Notification::getFacadeRoot();
        $queue = Queue::getFacadeRoot();
        $bus = Bus::getFacadeRoot();

        Artisan::call('safe-migrations:check', ['--path' => $this->fixture('side-effects'), '--realpath' => true, '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        // The migration is still analysed past its side effects.
        $this->assertSame(['drop-column'], array_column($report['findings'], 'rule'));

        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();

        // The services are put back for the real `migrate` that may follow.
        $this->assertSame($http, Http::getFacadeRoot());
        $this->assertSame($mail, Mail::getFacadeRoot());
        $this->assertSame($notifications, Notification::getFacadeRoot());
        $this->assertSame($queue, Queue::getFacadeRoot());
        $this->assertSame($bus, Bus::getFacadeRoot());
    }

    public function test_real_services_are_restored_after_the_check(): void
    {
        $http = Http::getFacadeRoot();
        $mail = Mail::getFacadeRoot();
        $queue = Queue::getFacadeRoot();
        $bus = Bus::getFacadeRoot();

        Artisan::call('safe-migrations:check', ['--path' => $this->fixture('side-effects'), '--realpath' => true, '--format' => 'json']);

        $this->assertSame($http, Http::getFacadeRoot());
        $this->assertSame($mail, Mail::getFacadeRoot());
        $this->assertSame($queue, Queue::getFacadeRoot());
        $this->assertSame($bus, Bus::getFacadeRoot());
        $this->assertSame($bus, $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class));
    }
}
