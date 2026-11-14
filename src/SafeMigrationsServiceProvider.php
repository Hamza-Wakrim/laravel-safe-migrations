<?php

namespace HamzaWakrim\SafeMigrations;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Console\CheckCommand;
use HamzaWakrim\SafeMigrations\Console\MigrateSafeCommand;
use HamzaWakrim\SafeMigrations\Exceptions\UnsafeMigrationException;

class SafeMigrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-migrations.php', 'safe-migrations');

        $this->app->singleton(Analyzer::class, fn ($app) => Analyzer::withDefaultRules(
            (array) $app['config']->get('safe-migrations.rules', []),
        ));

        $this->app->bind(MigrationInspector::class, fn ($app) => new MigrationInspector(
            $app['migrator'],
            $app['db'],
            $app->make(Analyzer::class),
            (array) $app['config']->get('safe-migrations.ignore_migrations', []),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/safe-migrations.php' => config_path('safe-migrations.php'),
        ], 'safe-migrations-config');

        $this->commands([CheckCommand::class, MigrateSafeCommand::class]);

        $this->app['events']->listen(CommandStarting::class, fn (CommandStarting $event) => $this->guardMigrateCommand($event));
    }

    /**
     * Stop a plain `php artisan migrate` before it starts when it would run unsafe migrations.
     */
    protected function guardMigrateCommand(CommandStarting $event): void
    {
        $config = (array) $this->app['config']->get('safe-migrations.guard', []);

        if ($event->command !== 'migrate'
            || ! filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($config['allow_unsafe'] ?? false, FILTER_VALIDATE_BOOL)
            || ! $this->app->environment((array) ($config['environments'] ?? ['production']))
            || $event->input->hasParameterOption('--pretend')) {
            return;
        }

        $path = $event->input->getParameterOption('--path', null);

        if (is_string($path) && $path !== '') {
            $paths = [$event->input->hasParameterOption('--realpath') ? $path : $this->app->basePath($path)];
        } else {
            $paths = array_merge($this->app['migrator']->paths(), [$this->app->databasePath('migrations')]);
        }

        $database = $event->input->getParameterOption('--database', null);

        $report = $this->app->make(MigrationInspector::class)->inspect($paths, is_string($database) && $database !== '' ? $database : null);

        if ($report->fails(filter_var($config['strict'] ?? false, FILTER_VALIDATE_BOOL))) {
            throw UnsafeMigrationException::fromReport($report);
        }
    }
}
