<?php

namespace HamzaWakrim\SafeMigrations;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MySqlConnection;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Report;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\SourceAnalyzer;
use HamzaWakrim\SafeMigrations\Analysis\Statement;
use HamzaWakrim\SafeMigrations\Attributes\AllowUnsafe;
use HamzaWakrim\SafeMigrations\Exceptions\DirectPdoAccessException;
use HamzaWakrim\SafeMigrations\Support\DryRunPdo;
use HamzaWakrim\SafeMigrations\Support\SideEffectFakes;
use Throwable;

/**
 * Runs migrations in "pretend" mode (nothing touches the database) and feeds
 * the SQL they would execute to the analyzer.
 */
final class MigrationInspector
{
    private readonly SourceAnalyzer $source;

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
        ?SourceAnalyzer $source = null,
    ) {
        $this->source = $source ?? new SourceAnalyzer($analyzer);
    }

    /**
     * Dry-run down() too, on the schema up() leaves behind, and report what a
     * rollback would break. Undoing what up() itself added (a new table or
     * column) is expected and not reported.
     *
     * @param  list<Statement>  $up
     * @return list<Finding>
     */
    private function rollbackFindings(object $migration, ?string $database, string $name, array $up, Context $context): array
    {
        $unsafe = $this->analyzer->severityFor('unsafe-rollback', Severity::Warning);
        $missing = $this->analyzer->optInSeverity('missing-down');

        if ($unsafe === null && $missing === null) {
            return [];
        }

        try {
            $down = method_exists($migration, 'down') ? $this->dryRun($migration, $database, $name, 'down') : [];
        } catch (Throwable) {
            return []; // A down() that throws (e.g. "this migration is irreversible") says so on purpose.
        }

        $findings = [];

        if ($down === [] && $up !== [] && $missing !== null) {
            $findings[] = new Finding(
                'missing-down',
                $missing,
                'This migration has no down() (or down() does nothing), so it cannot be rolled back.',
                'Write a down() that undoes up(), or throw an exception in down() to make the migration explicitly irreversible.',
                migration: $name,
            );
        }

        if ($unsafe === null) {
            return $findings;
        }

        // The state up() leaves behind, without changing the state the next migration is checked against.
        foreach ($this->analyzer->analyze($down, $context->forRollback($name)) as $finding) {
            if ($finding->subject !== null && str_contains($finding->subject, '.')) {
                [$table, $column] = explode('.', $finding->subject, 2);

                if ($context->isNewColumn($table, $column)) {
                    continue;
                }
            }

            $findings[] = new Finding(
                'unsafe-rollback',
                $unsafe,
                "On rollback ({$finding->rule}): {$finding->message}",
                $finding->suggestion,
                $finding->rule.':'.$finding->subject,
                $finding->sql,
                $name,
            );
        }

        return $findings;
    }

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
                    $results = [
                        ...$this->analyzer->analyze($statements, $context),
                        ...$this->rollbackFindings($migration, $database, $name, $statements, $context),
                    ];
                } catch (Throwable $e) {
                    $results = [];
                    $severity = $this->analyzer->severityFor('analysis-failed', Severity::Warning);

                    if ($severity !== null) {
                        $results[] = new Finding(
                            'analysis-failed',
                            $severity,
                            'Could not dry-run this migration: '.$e->getMessage(),
                            $e instanceof DirectPdoAccessException
                                ? 'Run the SQL through DB::statement() or DB::unprepared() instead of the PDO object, so it can be checked. Otherwise review it manually.'
                                : 'Migrations that read data (e.g. if (Schema::hasColumn(...))) cannot be fully analysed in pretend mode. Review it manually.',
                            migration: $name,
                        );
                    }
                }

                // Read the source too: it shows what the dry run cannot (skipped branches, data reads).
                $results = [...$results, ...$this->source->analyze($path, $name)];

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
     * Run the migration's up() (or down()) with pretend mode on for its own connection AND
     * every other configured connection, so a migration that also uses
     * Schema::connection('other') or DB::connection('other') never executes
     * anything for real during the check.
     *
     * @return list<Statement>
     */
    private function dryRun(object $migration, ?string $database, string $name, string $method = 'up'): array
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

        // Like Migrator::runMethod(), make the migration's connection the default
        // while up() runs, so DB::statement() and friends go where `migrate` sends them.
        $run = function () use ($migration, $primary, $method) {
            $previous = $this->connections->getDefaultConnection();

            try {
                $this->connections->setDefaultConnection($primary->getNameWithReadWriteType() ?? $previous);

                if (method_exists($migration, $method)) {
                    $migration->{$method}();
                }
            } finally {
                $this->connections->setDefaultConnection($previous);
            }
        };

        // Nest pretend() calls so every connection is pretending while up() runs.
        foreach (array_reverse($connections, true) as $id => $connection) {
            $inner = $run;
            $run = function () use ($connection, $inner, $id, &$logs) {
                $logs[$id] = $connection->pretend($inner);
            };
        }

        // Before the PDO guard goes in: which server each connection really talks to.
        $drivers = array_map($this->driverOf(...), $connections);

        // Pretend mode does not cover code that uses PDO directly, so put a
        // guard in front of every PDO until the dry run is over.
        $originals = [];

        foreach ($connections as $id => $connection) {
            $originals[$id] = [$connection->getRawPdo(), $connection->getRawReadPdo()];

            if ($originals[$id][0] !== null) {
                $connection->setPdo(new DryRunPdo($originals[$id][0]));
            }

            if ($originals[$id][1] !== null) {
                $connection->setReadPdo(new DryRunPdo($originals[$id][1]));
            }
        }

        try {
            // Pretend mode only covers the database; fake HTTP, mail, notifications and jobs too.
            SideEffectFakes::around($run);
        } finally {
            foreach ($connections as $id => $connection) {
                $connection->setPdo($originals[$id][0]);
                $connection->setReadPdo($originals[$id][1]);
            }
        }

        $statements = [];

        // Laravel only wraps the migration's own connection in a transaction.
        $primaryInTransaction = $this->runsInTransaction($migration, $primary);

        foreach ($connections as $id => $connection) {
            $inTransaction = $connection === $primary && $primaryInTransaction;

            foreach ($logs[$id] ?? [] as $query) {
                // DB::unprepared() can carry several statements in one string; check each one.
                foreach (Statement::splitStatements($query['query']) as $sql) {
                    $statements[] = new Statement($sql, $drivers[$id], $name, $inTransaction);
                }
            }
        }

        return $statements;
    }

    /**
     * The driver name the rules see. Many MariaDB apps still use the `mysql`
     * driver; MariaDB's online DDL differs from MySQL's, so report it as `mariadb`.
     */
    private function driverOf(Connection $connection): string
    {
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' && $connection instanceof MySqlConnection) {
            try {
                return $connection->isMaria() ? 'mariadb' : $driver;
            } catch (Throwable) {
                return $driver; // Can't reach the server: keep the configured driver.
            }
        }

        return $driver;
    }

    /**
     * Mirrors Migrator::runMigration(): the migration runs inside a transaction
     * when the schema grammar supports transactional DDL and the migration has
     * not set $withinTransaction = false.
     */
    private function runsInTransaction(object $migration, Connection $connection): bool
    {
        if (property_exists($migration, 'withinTransaction') && ! $migration->withinTransaction) {
            return false;
        }

        // Like Migrator::getSchemaGrammar(): the grammar is set lazily, despite its non-null docblock.
        /** @var \Illuminate\Database\Schema\Grammars\Grammar|null $grammar */
        $grammar = $connection->getSchemaGrammar();

        if ($grammar === null) {
            $connection->useDefaultSchemaGrammar();
            $grammar = $connection->getSchemaGrammar();
        }

        return $grammar->supportsSchemaTransactions();
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
