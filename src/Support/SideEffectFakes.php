<?php

namespace HamzaWakrim\SafeMigrations\Support;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Pretend mode only stops database statements. While a migration's up() runs
 * for the check, swap Laravel's HTTP client, mail, notifications, queue and
 * bus for fakes, so those side effects are recorded instead of happening, and
 * put the real services back afterwards (`migrate` may run right after).
 */
final class SideEffectFakes
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function around(Closure $callback): mixed
    {
        $app = Facade::getFacadeApplication();

        if ($app === null) {
            return $callback();
        }

        /** @var list<array{class-string<Facade>, mixed}> $originals */
        $originals = [];

        try {
            if (class_exists(HttpFactory::class)) {
                $originals[] = [Http::class, Http::getFacadeRoot()];
                // Http::fake() changes the existing factory in place, so fake a fresh one instead.
                $http = new HttpFactory($app->bound(EventDispatcher::class) ? $app->make(EventDispatcher::class) : null);
                $http->fake();
                Http::swap($http);
            }

            if ($app->bound('mail.manager')) {
                $originals[] = [Mail::class, Mail::getFacadeRoot()];
                Mail::fake();
            }

            if ($app->bound(ChannelManager::class)) {
                $originals[] = [Notification::class, Notification::getFacadeRoot()];
                Notification::fake();
            }

            if ($app->bound('queue')) {
                $originals[] = [Queue::class, Queue::getFacadeRoot()];
                Queue::fake();
            }

            if ($app->bound(BusDispatcher::class)) {
                $originals[] = [Bus::class, Bus::getFacadeRoot()];
                Bus::fake();
            }

            return $callback();
        } finally {
            foreach (array_reverse($originals) as [$facade, $original]) {
                $facade::swap($original);
            }
        }
    }
}
