<?php

namespace HamzaWakrim\SafeMigrations\Tests\Feature;

use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Statement;
use HamzaWakrim\SafeMigrations\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Proves the online DDL matrix the table-copy and unsupported-algorithm rules
 * rely on, on real MySQL and MariaDB servers: every statement the rules call
 * "rejected" really fails, and the "in place" alternatives really succeed.
 */
final class MySqlOnlineDdlTest extends TestCase
{
    /** @return array<string, array{string, list<string>, bool, list<string>}> */
    public static function statements(): array
    {
        $fk = 'ALTER TABLE posts ADD CONSTRAINT posts_user_fk FOREIGN KEY (user_id) REFERENCES users (id)';

        $cases = [
            // [setup, statement, server accepts it, rules expected]
            'instant index' => [[], 'ALTER TABLE users ADD INDEX users_email (email), ALGORITHM=INSTANT', false, ['unsupported-algorithm']],
            'instant column' => [[], 'ALTER TABLE users ADD bio TEXT NULL, ALGORITHM=INSTANT', true, []],
            'inplace foreign key with checks on' => [[], $fk.', ALGORITHM=INPLACE', false, ['unsupported-algorithm']],
            'inplace foreign key with checks off' => [['SET FOREIGN_KEY_CHECKS=0'], $fk.', ALGORITHM=INPLACE', true, []],
            'lock none foreign key with checks on' => [[], $fk.', LOCK=NONE', false, ['unsupported-algorithm']],
            'lock none fulltext' => [[], 'ALTER TABLE posts ADD FULLTEXT INDEX posts_body (body), LOCK=NONE', false, ['unsupported-algorithm']],
            'lock none auto increment column' => [[], 'ALTER TABLE logs ADD id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, LOCK=NONE', false, ['unsupported-algorithm']],
            'lock none stored column' => [[], "ALTER TABLE users ADD upper_name VARCHAR(255) AS (UPPER(name)) STORED, LOCK=NONE", false, ['unsupported-algorithm']],
            'inplace convert charset' => [[], 'ALTER TABLE users CONVERT TO CHARACTER SET latin1, ALGORITHM=INPLACE', false, ['unsupported-algorithm']],
            'inplace drop primary key alone' => [[], 'ALTER TABLE logs2 DROP PRIMARY KEY, ALGORITHM=INPLACE', false, ['unsupported-algorithm']],
            'inplace swap primary key' => [[], 'ALTER TABLE logs2 DROP PRIMARY KEY, ADD PRIMARY KEY (id, note), ALGORITHM=INPLACE', true, []],
            'inplace lock none index' => [[], 'ALTER TABLE users ADD INDEX users_name (name), ALGORITHM=INPLACE, LOCK=NONE', true, []],
            'virtual column instant' => [[], 'ALTER TABLE users ADD lower_name VARCHAR(255) AS (LOWER(name)) VIRTUAL, ALGORITHM=INSTANT', true, []],
        ];

        $all = [];

        foreach (['mysql', 'mariadb'] as $connection) {
            foreach ($cases as $name => $case) {
                $all["{$connection}: {$name}"] = [$connection, ...$case];
            }
        }

        // MariaDB 11.2+ copies with LOCK=NONE (writes go on), so these succeed there and the rule
        // leaves LOCK=NONE alone on MariaDB: the answer depends on the server version.
        foreach (['lock none foreign key with checks on' => true, 'lock none fulltext' => true, 'lock none stored column' => true, 'lock none auto increment column' => false] as $name => $accepted) {
            $all["mariadb: {$name}"][3] = $accepted;
            $all["mariadb: {$name}"][4] = [];
        }

        // MariaDB only.
        $all['mariadb: nocopy primary key'] = ['mariadb', [], 'ALTER TABLE logs ADD PRIMARY KEY (note), ALGORITHM=NOCOPY', false, ['unsupported-algorithm']];
        $all['mariadb: nocopy index'] = ['mariadb', [], 'ALTER TABLE users ADD INDEX users_name (name), ALGORITHM=NOCOPY, LOCK=NONE', true, []];

        return $all;
    }

    /**
     * @param  list<string>  $setup
     * @param  list<string>  $expectedRules
     */
    #[DataProvider('statements')]
    public function test_the_server_agrees_with_the_rules(string $connection, array $setup, string $sql, bool $accepted, array $expectedRules): void
    {
        $db = $this->connection($connection);

        $statements = array_map(fn (string $q) => new Statement($q, $db->getDriverName(), 'm'), [...$setup, $sql]);
        $rules = array_map(fn (Finding $f) => $f->rule, Analyzer::withDefaultRules()->analyze($statements));
        $this->assertSame($expectedRules, array_values(array_intersect($rules, ['unsupported-algorithm'])), 'rules');

        foreach ($setup as $statement) {
            $db->statement($statement);
        }

        try {
            $db->statement($sql);
            $this->assertTrue($accepted, "{$connection} accepted a statement the rules say it rejects: {$sql}");
        } catch (QueryException $e) {
            $this->assertFalse($accepted, "{$connection} rejected: ".$e->getMessage());
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function connection(string $name): Connection
    {
        if (! env('SAFE_MIGRATIONS_'.strtoupper($name).'_HOST')) {
            $this->markTestSkipped('Set SAFE_MIGRATIONS_'.strtoupper($name).'_HOST (or run via docker compose) to test '.$name.'.');
        }

        $db = DB::connection($name);

        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['posts', 'logs', 'logs2', 'users'] as $table) {
            $db->statement("DROP TABLE IF EXISTS {$table}");
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');

        $db->statement('CREATE TABLE users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->statement('CREATE TABLE posts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL) ENGINE=InnoDB');
        $db->statement('CREATE TABLE logs (note VARCHAR(255) NOT NULL) ENGINE=InnoDB');
        $db->statement('CREATE TABLE logs2 (id BIGINT UNSIGNED NOT NULL, note VARCHAR(100) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
        $db->statement("INSERT INTO users (name, email) VALUES ('a', 'a@example.com')");

        return $db;
    }
}
