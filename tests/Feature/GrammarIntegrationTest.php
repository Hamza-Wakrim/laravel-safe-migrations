<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Statement;
use HamzaWakrim\SafeMigrations\Tests\TestCase;

/**
 * Runs real Laravel schema code through each database grammar (in pretend
 * mode) so the rules are checked against the exact SQL Laravel generates.
 * MySQL and PostgreSQL cases need the database services from docker-compose.yml.
 */
final class GrammarIntegrationTest extends TestCase
{
    /** @return array<string, array{Closure, array<string, list<string>>}> */
    public static function operations(): array
    {
        $table = fn (Closure $callback) => fn (Builder $schema) => $schema->table('users', $callback);

        return [
            'drop column' => [$table(fn (Blueprint $t) => $t->dropColumn('name')), ['*' => ['drop-column']]],
            'rename column' => [$table(fn (Blueprint $t) => $t->renameColumn('name', 'full_name')), ['*' => ['rename-column']]],
            'rename table' => [fn (Builder $s) => $s->rename('users', 'members'), ['*' => ['rename-table']]],
            'drop table' => [fn (Builder $s) => $s->drop('users'), ['*' => ['drop-table']]],
            'drop table if exists' => [fn (Builder $s) => $s->dropIfExists('users'), ['*' => ['drop-table']]],
            'add NOT NULL column' => [$table(fn (Blueprint $t) => $t->string('nickname')), ['*' => ['add-not-null-column']]],
            'add nullable column' => [$table(fn (Blueprint $t) => $t->string('nickname')->nullable()), ['*' => []]],
            'add column with default' => [$table(fn (Blueprint $t) => $t->string('role')->default('member')), ['*' => []]],
            'change column' => [$table(fn (Blueprint $t) => $t->string('name', 500)->change()), [
                'mysql' => ['change-column'],
                'pgsql' => ['change-column', 'set-not-null'],
                'sqlite' => ['table-rebuild'],
            ]],
            'add index' => [$table(fn (Blueprint $t) => $t->index('email')), ['pgsql' => ['blocking-index'], '*' => []]],
            'add unique' => [$table(fn (Blueprint $t) => $t->unique('email')), ['pgsql' => ['blocking-index'], '*' => []]],
            'add foreign key' => [$table(fn (Blueprint $t) => $t->foreignId('team_id')->nullable()->constrained('teams')), [
                'pgsql' => ['blocking-foreign-key'],
                'sqlite' => ['table-rebuild'],
                '*' => [],
            ]],
            'new table changes' => [function (Builder $s) {
                $s->create('teams', function (Blueprint $t) {
                    $t->id();
                    $t->string('name')->index();
                });
                $s->table('teams', fn (Blueprint $t) => $t->string('slug'));
                $s->table('teams', fn (Blueprint $t) => $t->dropColumn('name'));
                $s->drop('teams');
            }, ['*' => []]],
            'update without where' => [fn (Builder $s, Connection $db) => $db->table('users')->update(['name' => 'x']), ['*' => ['data-mutation']]],
            'update with where' => [fn (Builder $s, Connection $db) => $db->table('users')->where('id', 1)->update(['name' => 'x']), ['*' => []]],
            'truncate' => [fn (Builder $s, Connection $db) => $db->table('users')->truncate(), ['*' => ['data-mutation']]],
        ];
    }

    /** @return iterable<string, array{string, Closure, array<string, list<string>>}> */
    public static function cases(): iterable
    {
        foreach (['sqlite' => 'testing', 'mysql' => 'mysql', 'pgsql' => 'pgsql'] as $driver => $connection) {
            foreach (self::operations() as $name => [$operation, $expected]) {
                yield "{$driver}: {$name}" => [$connection, $operation, $expected[$driver] ?? $expected['*']];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_rules_match_laravel_generated_sql(string $connection, Closure $operation, array $expected): void
    {
        $this->requireConnection($connection);

        $db = DB::connection($connection);
        $this->createUsersTable($db);

        $queries = $db->pretend(fn () => $operation($db->getSchemaBuilder(), $db));
        $statements = array_map(fn (array $q) => new Statement($q['query'], $db->getDriverName(), 'case'), $queries);

        $rules = array_values(array_unique(array_map(
            fn (Finding $f) => $f->rule,
            $this->app->make(Analyzer::class)->analyze($statements),
        )));

        sort($rules);
        sort($expected);

        $this->assertSame($expected, $rules, "SQL:\n".implode("\n", array_column($queries, 'query')));
    }

    private function requireConnection(string $connection): void
    {
        if ($connection === 'testing') {
            return;
        }

        if (! env('SAFE_MIGRATIONS_'.strtoupper($connection).'_HOST')) {
            $this->markTestSkipped("Set SAFE_MIGRATIONS_".strtoupper($connection)."_HOST (or run via docker compose) to test {$connection}.");
        }
    }

    private function createUsersTable(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->dropIfExists('users');
        $schema->dropIfExists('teams');
        $schema->create('teams', fn (Blueprint $t) => $t->id());
        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
        });
    }
}
