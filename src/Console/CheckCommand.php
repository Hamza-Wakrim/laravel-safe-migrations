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

    /** @var Migrator */
    protected $migrator;

    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    public function handle(MigrationInspector $inspector): int
    {
        $format = $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->components->error('Invalid --format. Use "table" or "json".');

            return self::INVALID;
        }

        $report = $inspector->inspect(
            $this->getMigrationPaths(),
            $this->databaseOption(),
            pendingOnly: ! $this->option('all'),
        );

        $this->renderReport($report, $format);

        return $report->fails((bool) $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
