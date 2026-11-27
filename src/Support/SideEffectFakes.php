<?php

namespace HamzaWakrim\SafeMigrations\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
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
    /** @var list<array{class-string<Facade>, mixed}> The real facade roots, in the order they were swapped. */
    private array $originals = [];

    private ?EventDispatcher $modelDispatcher;

    private ?string $storageRoot = null;

    private function __construct()
    {
        $this->modelDispatcher = class_exists(Model::class) ? Model::getEventDispatcher() : null;
    }

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

        $fakes = new self;

        try {
            $fakes->install($app);

            return $callback();
        } finally {
            $fakes->restore();
        }
    }

    private function install(Application $app): void
    {
        if (class_exists(HttpFactory::class)) {
            $this->originals[] = [Http::class, Http::getFacadeRoot()];
            // Http::fake() changes the existing factory in place, so fake a fresh one instead.
            $http = new HttpFactory($app->bound(EventDispatcher::class) ? $app->make(EventDispatcher::class) : null);
            $http->fake();
            Http::swap($http);
        }

        if ($app->bound('mail.manager')) {
            $this->originals[] = [Mail::class, Mail::getFacadeRoot()];
            Mail::fake();
        }

        if ($app->bound(ChannelManager::class)) {
            $this->originals[] = [Notification::class, Notification::getFacadeRoot()];
            Notification::fake();
        }

        if ($app->bound('queue')) {
            $this->originals[] = [Queue::class, Queue::getFacadeRoot()];
            Queue::fake();
        }

        if ($app->bound(BusDispatcher::class)) {
            $this->originals[] = [Bus::class, Bus::getFacadeRoot()];
            Bus::fake();
        }

        if ($app->bound('events')) {
            $events = Event::getFacadeRoot();
            $this->originals[] = [Event::class, $events];
            // Like Event::fake(), but the model dispatcher is put back below.
            $fake = new EventFake($events);
            Event::swap($fake);

            if ($this->modelDispatcher !== null) {
                Model::setEventDispatcher($fake);
            }
        }

        if ($app->bound('cache') && class_exists(CacheManager::class)) {
            $this->originals[] = [Cache::class, Cache::getFacadeRoot()];
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
            $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'safe-migrations-'.bin2hex(random_bytes(6));
            $this->originals[] = [Storage::class, Storage::getFacadeRoot()];
            Storage::swap(new class($app, $this->storageRoot) extends FilesystemManager
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
            $this->originals[] = [Process::class, Process::getFacadeRoot()];
            // Like Http, Process::fake() changes the existing factory in place.
            Process::swap((new ProcessFactory)->fake());
        }

        if ($app->bound(ConsoleKernel::class)) {
            $this->originals[] = [Artisan::class, Artisan::getFacadeRoot()];
            Artisan::swap(new FakeConsoleKernel);
        }
    }

    /** Put the real services back, in reverse order, and remove the temporary storage folder. */
    private function restore(): void
    {
        foreach (array_reverse($this->originals) as [$facade, $original]) {
            $facade::swap($original);
        }

        if ($this->modelDispatcher !== null) {
            Model::setEventDispatcher($this->modelDispatcher);
        }

        if ($this->storageRoot !== null) {
            (new Filesystem)->deleteDirectory($this->storageRoot);
        }
    }
}
