<?php

namespace HamzaWakrim\SafeMigrations;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Report;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;
use HamzaWakrim\SafeMigrations\Attributes\AllowUnsafe;
use Throwable;

/**
 * Runs migrations in "pretend" mode (nothing touches the database) and feeds
 * the SQL they would execute to the analyzer.
 */
final class MigrationInspector
{
    /** @param  list<string>  $ignoredMigrations */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ConnectionResolverInterface $connections,
        private readonly Analyzer $analyzer,
        private readonly array $ignoredMigrations = [],
    ) {}

    /** @param  list<string>  $paths */
    public function inspect(array $paths, ?string $database = null, bool $pendingOnly = true): Report
    {
        return $this->migrator->usingConnection($database, function () use ($paths, $database, $pendingOnly) {
            $files = $this->migrator->getMigrationFiles($paths);

            if ($pendingOnly && $this->migrator->repositoryExists()) {
                $files = array_diff_key($files, array_flip($this->migrator->getRepository()->getRan()));
            }

            $context = new Context;
            $analysed = [];
            $findings = [];
            $suppressed = [];

            foreach ($files as $name => $path) {
                if (in_array($name, $this->ignoredMigrations, true)) {
                    continue;
                }

                $analysed[] = $name;

                try {
                    $migration = $this->resolve($path);
                    $connection = $this->connections->connection($migration->getConnection() ?? $database);
                    $queries = $connection->pretend(function () use ($migration) {
                        if (method_exists($migration, 'up')) {
                            $migration->up();
                        }
                    });
                } catch (Throwable $e) {
                    $findings[] = new Finding(
                        'analysis-failed',
                        Severity::Warning,
                        'Could not dry-run this migration: '.$e->getMessage(),
                        'Migrations that read data (e.g. if (Schema::hasColumn(...))) cannot be fully analysed in pretend mode. Review it manually.',
                        migration: $name,
                    );

                    continue;
                }

                $statements = array_map(
                    fn (array $query) => new Statement($query['query'], $connection->getDriverName(), $name),
                    $queries,
                );

                $allowances = AllowUnsafe::on($migration);

                foreach ($this->analyzer->analyze($statements, $context) as $finding) {
                    $allowed = array_filter($allowances, fn (AllowUnsafe $a) => $a->allows($finding->rule)) !== [];

                    if ($allowed) {
                        $suppressed[] = $finding;
                    } else {
                        $findings[] = $finding;
                    }
                }
            }

            return new Report($analysed, $findings, $suppressed);
        });
    }

    /**
     * Use the migrator's own (protected) resolver so anonymous migrations are
     * cached exactly the way `migrate` expects when it runs afterwards.
     */
    private function resolve(string $path): object
    {
        return (fn (string $path) => $this->resolvePath($path))->call($this->migrator, $path);
    }
}
