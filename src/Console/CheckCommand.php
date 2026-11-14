<?php

namespace HamzaWakrim\SafeMigrations\Console;

use Illuminate\Database\Console\Migrations\BaseCommand;
use Illuminate\Database\Migrations\Migrator;
use HamzaWakrim\SafeMigrations\MigrationInspector;

final class CheckCommand extends BaseCommand
{
    use RendersReport;

    protected $signature = 'safe-migrations:check
        {--database= : The database connection to use}
        {--path=* : The path(s) to the migration files}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--all : Check every migration, not only pending ones}
        {--strict : Fail on warnings as well as errors}
        {--format=table : Output format (table or json)}';

    protected $description = 'Dry-run pending migrations and report operations that are unsafe for zero-downtime deploys';

    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    public function handle(MigrationInspector $inspector): int
    {
        $report = $inspector->inspect(
            $this->getMigrationPaths(),
            $this->option('database') ?: null,
            pendingOnly: ! $this->option('all'),
        );

        $this->renderReport($report, (string) $this->option('format'));

        return $report->fails((bool) $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
