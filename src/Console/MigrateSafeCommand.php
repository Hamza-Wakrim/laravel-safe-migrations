<?php

namespace HamzaWakrim\SafeMigrations\Console;

use Illuminate\Database\Console\Migrations\BaseCommand;
use Illuminate\Database\Migrations\Migrator;
use HamzaWakrim\SafeMigrations\MigrationInspector;

final class MigrateSafeCommand extends BaseCommand
{
    use RendersReport;

    protected $signature = 'migrate:safe
        {--database= : The database connection to use}
        {--path=* : The path(s) to the migration files}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--force : Force the operation to run when in production}
        {--step : Force the migrations to be run so they can be rolled back individually}
        {--seed : Indicates if the seed task should be re-run}
        {--strict : Refuse to run on warnings as well as errors}
        {--unsafe : Run the migrations even if unsafe operations are found}';

    protected $description = 'Check pending migrations for unsafe operations, then run them';

    /** @var Migrator */
    protected $migrator;

    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    public function handle(MigrationInspector $inspector): int
    {
        $database = $this->databaseOption();
        $report = $inspector->inspect($this->getMigrationPaths(), $database);

        if ($report->migrations !== []) {
            $this->renderReport($report);
        }

        if ($report->fails((bool) $this->option('strict'))) {
            if (! $this->option('unsafe')) {
                $this->components->error('Migrations not run. Fix the issues above, mark reviewed migrations with #[AllowUnsafe], or re-run with --unsafe.');

                return self::FAILURE;
            }

            $this->components->warn('Running unsafe migrations because --unsafe was given.');
        }

        return $this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $this->option('path') ?: null,
            '--realpath' => $this->option('realpath'),
            '--force' => $this->option('force'),
            '--step' => $this->option('step'),
            '--seed' => $this->option('seed'),
        ]));
    }
}
