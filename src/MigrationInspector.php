<?php

namespace HamzaWakrim\SafeMigrations;

use Illuminate\Database\Connection;
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
    /**
     * @param  list<string>  $ignoredMigrations
     * @param  list<string>  $connectionNames  Every configured connection; all of them pretend during the dry run.
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ConnectionResolverInterface $connections,
        private readonly Analyzer $analyzer,
        private readonly array $ignoredMigrations = [],
        private readonly array $connectionNames = [],
    ) {}

    /** @param  array<string>  $paths */
    public function inspect(array $paths, ?string $database = null, bool $pendingOnly = true): Report
    {
        $inspect = function () use ($paths, $database, $pendingOnly): Report {
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

                $migration = null;

                try {
                    $migration = $this->resolve($path);
                    $statements = $this->dryRun($migration, $database, $name);
                    $results = $this->analyzer->analyze($statements, $context);
                } catch (Throwable $e) {
                    $results = [];
                    $severity = $this->analyzer->severityFor('analysis-failed', Severity::Warning);

                    if ($severity !== null) {
                        $results[] = new Finding(
                            'analysis-failed',
                            $severity,
                            'Could not dry-run this migration: '.$e->getMessage(),
                            'Migrations that read data (e.g. if (Schema::hasColumn(...))) cannot be fully analysed in pretend mode. Review it manually.',
                            migration: $name,
                        );
                    }
                }

                $allowances = $migration !== null ? AllowUnsafe::on($migration) : [];

                foreach ($results as $finding) {
                    $allowed = array_filter($allowances, fn (AllowUnsafe $a) => $a->allows($finding->rule)) !== [];

                    if ($allowed) {
                        $suppressed[] = $finding;
                    } else {
                        $findings[] = $finding;
                    }
                }
            }

            return new Report($analysed, $findings, $suppressed);
        };

        // usingConnection() is documented as taking a string, so only switch when a connection was asked for.
        return $database === null ? $inspect() : $this->migrator->usingConnection($database, $inspect);
    }

    /**
     * Run the migration's up() with pretend mode on for its own connection AND
     * every other configured connection, so a migration that also uses
     * Schema::connection('other') or DB::connection('other') never executes
     * anything for real during the check.
     *
     * @return list<Statement>
     */
    private function dryRun(object $migration, ?string $database, string $name): array
    {
        $own = method_exists($migration, 'getConnection') ? $migration->getConnection() : null;
        $primary = $this->connections->connection(is_string($own) ? $own : $database);

        if (! $primary instanceof Connection) {
            throw new \RuntimeException('Unsupported database connection type: '.$primary::class);
        }

        /** @var array<int, Connection> $connections */
        $connections = [spl_object_id($primary) => $primary];

        foreach ($this->connectionNames as $connectionName) {
            try {
                $connection = $this->connections->connection($connectionName);
            } catch (Throwable) {
                continue; // Misconfigured or unavailable driver: nothing can run on it either.
            }

            if ($connection instanceof Connection) {
                $connections[spl_object_id($connection)] ??= $connection;
            }
        }

        /** @var array<int, array<int, array{query: string}>> $logs */
        $logs = [];

        $run = function () use ($migration) {
            if (method_exists($migration, 'up')) {
                $migration->up();
            }
        };

        // Nest pretend() calls so every connection is pretending while up() runs.
        foreach (array_reverse($connections, true) as $id => $connection) {
            $inner = $run;
            $run = function () use ($connection, $inner, $id, &$logs) {
                $logs[$id] = $connection->pretend($inner);
            };
        }

        $run();

        $statements = [];

        foreach ($connections as $id => $connection) {
            foreach ($logs[$id] ?? [] as $query) {
                $statements[] = new Statement($query['query'], $connection->getDriverName(), $name);
            }
        }

        return $statements;
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
