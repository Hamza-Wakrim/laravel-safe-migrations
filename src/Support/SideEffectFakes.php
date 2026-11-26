<?php

namespace HamzaWakrim\SafeMigrations\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\EventFake;

/**
 * Pretend mode only stops database statements. While a migration's up() runs
 * for the check, swap Laravel's side-effect services for fakes, so those side
 * effects are recorded (or kept in memory / a temporary folder) instead of
 * happening, and put the real services back afterwards (`migrate` may run
 * right after):
 *
 * HTTP client, mail, notifications, queue, bus, events, cache (in memory),
 * storage (a temporary folder), processes and Artisan::call().
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
        $modelDispatcher = class_exists(Model::class) ? Model::getEventDispatcher() : null;
        $storageRoot = null;

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

            if ($app->bound('events')) {
                $events = Event::getFacadeRoot();
                $originals[] = [Event::class, $events];
                // Like Event::fake(), but the model dispatcher is put back below.
                $fake = new EventFake($events);
                Event::swap($fake);

                if ($modelDispatcher !== null) {
                    Model::setEventDispatcher($fake);
                }
            }

            if ($app->bound('cache') && class_exists(CacheManager::class)) {
                $originals[] = [Cache::class, Cache::getFacadeRoot()];
                Cache::swap(new class($app) extends CacheManager
                {
                    public function resolve($name)
                    {
                        return $this->repository(new ArrayStore, ['store' => $name]);
                    }

                    /** @param  array<string, mixed>  $config */
                    public function build(array $config)
                    {
                        return $this->repository(new ArrayStore, ['store' => 'ondemand']);
                    }
                });
            }

            if ($app->bound('filesystem') && class_exists(FilesystemManager::class)) {
                $storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'safe-migrations-'.bin2hex(random_bytes(6));
                $originals[] = [Storage::class, Storage::getFacadeRoot()];
                Storage::swap(new class($app, $storageRoot) extends FilesystemManager
                {
                    /** @param  \Illuminate\Contracts\Foundation\Application  $app */
                    public function __construct($app, private readonly string $root)
                    {
                        parent::__construct($app);
                    }

                    /** @param  array<string, mixed>|null  $config */
                    protected function resolve($name, $config = null)
                    {
                        return $this->createLocalDriver(['root' => $this->root.DIRECTORY_SEPARATOR.$name], $name);
                    }
                });
            }

            if (class_exists(ProcessFactory::class)) {
                $originals[] = [Process::class, Process::getFacadeRoot()];
                // Like Http, Process::fake() changes the existing factory in place.
                Process::swap((new ProcessFactory)->fake());
            }

            if ($app->bound(ConsoleKernel::class)) {
                $originals[] = [Artisan::class, Artisan::getFacadeRoot()];
                Artisan::swap(new FakeConsoleKernel);
            }

            return $callback();
        } finally {
            foreach (array_reverse($originals) as [$facade, $original]) {
                $facade::swap($original);
            }

            if ($modelDispatcher !== null) {
                Model::setEventDispatcher($modelDispatcher);
            }

            if ($storageRoot !== null) {
                (new Filesystem)->deleteDirectory($storageRoot);
            }
        }
    }
}
