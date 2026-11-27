<?php

namespace HamzaWakrim\SafeMigrations\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use HamzaWakrim\SafeMigrations\Analysis\Analyzer;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Severity;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

final class AnalyzerTest extends TestCase
{
    /** @return array<string, array{string, string, list<string>}> */
    public static function statements(): array
    {
        return [
            'mysql drop two columns' => ['mysql', 'alter table `users` drop `name`, drop `age`', ['drop-column', 'drop-column']],
            'pgsql drop column' => ['pgsql', 'alter table "users" drop column "name"', ['drop-column']],
            'mysql drop index is fine' => ['mysql', 'alter table `users` drop index `users_email_index`', []],
            'mysql drop foreign is fine' => ['mysql', 'alter table `posts` drop foreign key `posts_user_id_foreign`', []],
            'pgsql drop constraint is fine' => ['pgsql', 'alter table "users" drop constraint "users_email_unique"', []],
            'drop table' => ['pgsql', 'drop table "users"', ['drop-table']],
            'drop table if exists' => ['mysql', 'drop table if exists `users`', ['drop-table']],
            'rename table (alter)' => ['pgsql', 'alter table "users" rename to "members"', ['rename-table']],
            'rename table (mysql)' => ['mysql', 'rename table `users` to `members`', ['rename-table']],
            'rename column' => ['mysql', 'alter table `users` rename column `name` to `full_name`', ['rename-column']],
            'legacy mysql rename via change' => ['mysql', 'alter table `users` change `name` `full_name` varchar(255) not null', ['rename-column']],
            'mysql modify' => ['mysql', 'alter table `users` modify `name` varchar(500) not null', ['change-column']],
            'pgsql type change + set not null' => ['pgsql', 'alter table "users" alter column "name" type varchar(500), alter column "name" set not null', ['change-column', 'set-not-null']],
            'add not null without default (pgsql)' => ['pgsql', 'alter table "users" add column "nickname" varchar(255) not null', ['add-not-null-column']],
            'add not null with default' => ['pgsql', "alter table \"users\" add column \"role\" varchar(255) not null default 'member'", []],
            'add nullable' => ['mysql', 'alter table `users` add `nickname` varchar(255) null', []],
            'add auto increment (blocks writes)' => ['mysql', 'alter table `users` add `id` bigint unsigned not null auto_increment primary key', ['table-copy']],
            'add generated column' => ['mysql', 'alter table `users` add `full` varchar(255) as (concat(first, last)) not null', []],
            'pgsql create index' => ['pgsql', 'create index "users_email_index" on "users" ("email")', ['blocking-index']],
            'pgsql create index concurrently' => ['pgsql', 'create index concurrently "users_email_index" on "users" ("email")', []],
            'pgsql unique constraint' => ['pgsql', 'alter table "users" add constraint "users_email_unique" unique ("email")', ['blocking-index']],
            'mysql index is online' => ['mysql', 'alter table `users` add index `users_email_index`(`email`)', []],
            'pgsql foreign key' => ['pgsql', 'alter table "posts" add constraint "posts_user_id_foreign" foreign key ("user_id") references "users" ("id")', ['blocking-foreign-key']],
            'pgsql foreign key not valid' => ['pgsql', 'alter table "posts" add constraint "posts_user_id_foreign" foreign key ("user_id") references "users" ("id") not valid', []],
            'update without where' => ['mysql', 'update `users` set `name` = ?', ['data-mutation']],
            'update with where' => ['mysql', 'update `users` set `name` = ? where `id` = ?', []],
            'delete without where' => ['pgsql', 'delete from "users"', ['data-mutation']],
            'truncate' => ['pgsql', 'truncate table "users" restart identity cascade', ['data-mutation']],
            'pgsql unique using index (README recipe)' => ['pgsql', 'ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE USING INDEX users_email_unique', []],
            'pgsql check not valid (README recipe)' => ['pgsql', 'ALTER TABLE users ADD CONSTRAINT users_timezone_not_null CHECK (timezone IS NOT NULL) NOT VALID', []],
            'pgsql validate constraint (README recipe)' => ['pgsql', 'ALTER TABLE users VALIDATE CONSTRAINT users_timezone_not_null', []],
            'pgsql raw set not null (README recipe)' => ['pgsql', 'ALTER TABLE users ALTER COLUMN timezone SET NOT NULL', ['set-not-null']],
            'pgsql raw drop constraint (README recipe)' => ['pgsql', 'ALTER TABLE users DROP CONSTRAINT users_timezone_not_null', []],
            'pgsql raw foreign key not valid (README recipe)' => ['pgsql', 'ALTER TABLE posts ADD CONSTRAINT posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) NOT VALID', []],
            'insert is fine' => ['mysql', 'insert into `users` (`name`) values (?)', []],
            'create table is fine' => ['mysql', 'create table `teams` (`id` bigint unsigned not null auto_increment primary key)', []],
            'line comment before statement' => ['mysql', "-- clean up\nALTER TABLE users DROP COLUMN legacy", ['drop-column']],
            'block comment before statement' => ['pgsql', '/* old logs */ DROP TABLE logs', ['drop-table']],
            'line comment inside statement' => ['mysql', "ALTER TABLE users -- note\n DROP COLUMN a", ['drop-column']],
            'block comment inside statement' => ['pgsql', 'ALTER TABLE users /* x */ DROP COLUMN b', ['drop-column']],
            'comment between clauses' => ['mysql', "ALTER TABLE users DROP COLUMN a, -- and\n DROP COLUMN b", ['drop-column', 'drop-column']],
            'comment inside table name quotes is kept' => ['pgsql', 'DROP TABLE "a--b"', ['drop-table']],
            'mysql executable comment is checked' => ['mysql', '/*!40000 DROP TABLE logs */', ['drop-table']],
            'commented-out statement is ignored' => ['mysql', '-- DROP TABLE users', []],
        ];
    }

    #[DataProvider('statements')]
    public function test_it_flags_statements(string $driver, string $sql, array $expected): void
    {
        $this->assertSame($expected, $this->rules([new Statement($sql, $driver, 'm')]));
    }

    /** @return array<string, array{string, string, bool, list<string>}> */
    public static function concurrentStatements(): array
    {
        return [
            'create index concurrently in transaction' => ['pgsql', 'create index concurrently "users_email_index" on "users" ("email")', true, ['concurrent-in-transaction']],
            'create unique index concurrently in transaction' => ['pgsql', 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS users_email_unique ON users (email)', true, ['concurrent-in-transaction']],
            'drop index concurrently in transaction' => ['pgsql', 'DROP INDEX CONCURRENTLY IF EXISTS users_email_index', true, ['concurrent-in-transaction']],
            'reindex concurrently in transaction' => ['pgsql', 'REINDEX INDEX CONCURRENTLY users_email_index', true, ['concurrent-in-transaction']],
            'detach partition concurrently in transaction' => ['pgsql', 'ALTER TABLE events DETACH PARTITION events_2024 CONCURRENTLY', true, ['concurrent-in-transaction']],
            'create index concurrently without transaction' => ['pgsql', 'create index concurrently "users_email_index" on "users" ("email")', false, []],
            'drop index concurrently without transaction' => ['pgsql', 'DROP INDEX CONCURRENTLY users_email_index', false, []],
            'plain create index in transaction' => ['pgsql', 'create index "users_email_index" on "users" ("email")', true, ['blocking-index']],
            'refresh materialized view concurrently is allowed' => ['pgsql', 'REFRESH MATERIALIZED VIEW CONCURRENTLY stats', true, []],
            'concurrently inside a string is ignored' => ['pgsql', "update settings set note = 'create index concurrently' where id = 1", true, []],
            'not postgres' => ['sqlite', 'create index concurrently users_email_index on users (email)', true, []],
        ];
    }

    #[DataProvider('concurrentStatements')]
    public function test_concurrently_inside_a_transaction_is_flagged(string $driver, string $sql, bool $inTransaction, array $expected): void
    {
        $this->assertSame($expected, $this->rules([new Statement($sql, $driver, 'm', $inTransaction)]));
    }

    public function test_concurrently_inside_a_transaction_is_an_error(): void
    {
        $findings = Analyzer::withDefaultRules()->analyze([new Statement('DROP INDEX CONCURRENTLY users_email_index', 'pgsql', 'm', true)]);

        $this->assertSame(Severity::Error, $findings[0]->severity);
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function lockTimeoutStatements(): array
    {
        return [
            'pgsql alter without timeout' => ['pgsql', ['alter table "users" add column "bio" text null'], ['users']],
            'pgsql set lock_timeout first' => ['pgsql', ["SET lock_timeout = '5s'", 'alter table "users" add column "bio" text null'], []],
            'pgsql set local lock_timeout to' => ['pgsql', ["SET LOCAL lock_timeout TO '3s'", 'alter table "users" add column "bio" text null'], []],
            'pgsql lock_timeout 0 waits forever' => ['pgsql', ["SET lock_timeout = '5s'", 'SET lock_timeout = 0', 'alter table "users" add column "bio" text null'], ['users']],
            'pgsql timeout set after the alter' => ['pgsql', ['alter table "users" add column "bio" text null', "SET lock_timeout = '5s'"], ['users']],
            'pgsql create index' => ['pgsql', ['create index "users_email_index" on "users" ("email")'], ['users']],
            'pgsql create index concurrently' => ['pgsql', ['create index concurrently "users_email_index" on "users" ("email")'], []],
            'mysql alter without timeout' => ['mysql', ['alter table `users` add `bio` text null'], ['users']],
            'mysql session lock_wait_timeout' => ['mysql', ['SET SESSION lock_wait_timeout = 5', 'alter table `users` add `bio` text null'], []],
            'mysql @@session lock_wait_timeout' => ['mysql', ['SET @@session.lock_wait_timeout=5', 'alter table `users` add `bio` text null'], []],
            'mysql global lock_wait_timeout does not count' => ['mysql', ['SET GLOBAL lock_wait_timeout = 5', 'alter table `users` add `bio` text null'], ['users']],
            'mysql rename table' => ['mysql', ['rename table `users` to `members`'], ['users']],
            'mariadb alter without timeout' => ['mariadb', ['alter table `users` add `bio` text null'], ['users']],
            'mariadb alter wait' => ['mariadb', ['ALTER TABLE users WAIT 5 ADD bio text null'], []],
            'mariadb alter nowait' => ['mariadb', ['ALTER TABLE users NOWAIT ADD bio text null'], []],
            'mariadb set statement' => ['mariadb', ['SET STATEMENT lock_wait_timeout=5 FOR ALTER TABLE users ADD bio text null'], []],
            'mariadb create index wait' => ['mariadb', ['CREATE INDEX users_bio ON users (bio) WAIT 2'], []],
            'mariadb on mysql driver with wait' => ['mysql', ['ALTER TABLE users WAIT 5 ADD bio text null'], []],
            'timeout in another migration does not count' => ['pgsql', ["SET lock_timeout = '5s'", 'alter table "users" add column "bio" text null'], ['users'], ['a', 'b']],
            'new table is fine' => ['pgsql', ['create table "teams" ("id" serial primary key not null)', 'alter table "teams" add column "bio" text null'], []],
            'sqlite is skipped' => ['sqlite', ['alter table "users" add column "bio" text'], []],
            'update is not a lock statement' => ['mysql', ['update `users` set `bio` = ? where `id` = ?'], []],
        ];
    }

    /**
     * @param  list<string>  $sql
     * @param  list<string>  $expectedTables
     * @param  list<string>|null  $migrations
     */
    #[DataProvider('lockTimeoutStatements')]
    public function test_lock_timeout_rule(string $driver, array $sql, array $expectedTables, ?array $migrations = null): void
    {
        $statements = array_map(fn (string $q, int $i) => new Statement($q, $driver, $migrations[$i] ?? 'm'), $sql, array_keys($sql));

        $findings = array_filter(
            Analyzer::withDefaultRules(['lock-timeout' => 'warning'])->analyze($statements),
            fn (Finding $f) => $f->rule === 'lock-timeout',
        );

        $this->assertSame($expectedTables, array_values(array_map(fn (Finding $f) => $f->subject, $findings)));
    }

    public function test_lock_timeout_rule_is_off_by_default(): void
    {
        $this->assertSame(['drop-column'], $this->rules([new Statement('alter table "users" drop column "bio"', 'pgsql', 'm')]));
        $this->assertNotContains('lock-timeout', Analyzer::withDefaultRules()->ruleIds());
        $this->assertContains('lock-timeout', Analyzer::withDefaultRules(['lock-timeout' => 'error'])->ruleIds());
    }

    public function test_mariadb_wait_and_set_statement_do_not_hide_other_rules(): void
    {
        $this->assertSame(['drop-column'], $this->rules([new Statement('ALTER TABLE users WAIT 5 DROP COLUMN bio', 'mariadb', 'm')]));
        $this->assertSame(['drop-column'], $this->rules([new Statement('SET STATEMENT lock_wait_timeout=5 FOR ALTER TABLE users DROP COLUMN bio', 'mariadb', 'm')]));
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function mysqlAlgorithmStatements(): array
    {
        $fk = 'alter table `posts` add constraint `posts_user_id_foreign` foreign key (`user_id`) references `users` (`id`)';

        return [
            'foreign key copies' => ['mysql', [$fk], ['table-copy:posts:add-foreign']],
            'foreign key copies (mariadb)' => ['mariadb', [$fk], ['table-copy:posts:add-foreign']],
            'foreign key with checks off is in place' => ['mysql', ['SET FOREIGN_KEY_CHECKS=0;', $fk, 'SET FOREIGN_KEY_CHECKS=1;'], []],
            'foreign key after checks back on copies' => ['mysql', ['SET FOREIGN_KEY_CHECKS=0', 'SET FOREIGN_KEY_CHECKS=1', $fk], ['table-copy:posts:add-foreign']],
            'foreign key on new table' => ['mysql', ['create table `posts` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null)', $fk], []],
            'foreign key is not a mysql thing on pgsql' => ['pgsql', ['alter table "posts" add constraint "f" foreign key ("user_id") references "users" ("id") not valid'], []],
            'convert charset' => ['mysql', ['ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4'], ['table-copy:users:convert-charset']],
            'drop primary key alone' => ['mysql', ['alter table `users` drop primary key'], ['table-copy:users:drop-primary']],
            'swap primary key in place' => ['mysql', ['ALTER TABLE users DROP PRIMARY KEY, ADD PRIMARY KEY (id, team_id)'], []],
            'stored generated column' => ['mysql', ['alter table `users` add `full_name` varchar(255) as (concat(first, \' \', last)) stored'], ['table-copy:users:stored-column']],
            'virtual generated column is instant' => ['mysql', ['alter table `users` add `full_name` varchar(255) as (concat(first, last)) virtual'], []],
            'mariadb persistent column' => ['mariadb', ['ALTER TABLE users ADD full_name varchar(255) AS (CONCAT(first, last)) PERSISTENT'], ['table-copy:users:stored-column']],
            'fulltext index blocks writes' => ['mysql', ['alter table `posts` add fulltext `posts_body_fulltext`(`body`)'], ['table-copy:posts:add-fulltext']],
            'create fulltext index' => ['mariadb', ['CREATE FULLTEXT INDEX posts_body ON posts (body)'], ['table-copy:posts:add-fulltext']],
            'spatial index blocks writes' => ['mysql', ['alter table `places` add spatial index `places_point_spatialindex`(`point`)'], ['table-copy:places:add-spatial']],
            'algorithm copy' => ['mysql', ['ALTER TABLE users ADD INDEX i (email), ALGORITHM=COPY'], ['table-copy:users:algorithm-copy']],
            'plain index is online' => ['mysql', ['alter table `users` add index `users_email_index`(`email`)'], []],
            'nullable column is instant' => ['mariadb', ['alter table `users` add `bio` text null'], []],
            'lock none guards a copy' => ['mysql', ['ALTER TABLE users ADD INDEX i (email), LOCK=NONE'], []],
            'instant add column is fine' => ['mysql', ['ALTER TABLE users ADD bio text NULL, ALGORITHM=INSTANT'], []],
            'instant index is rejected' => ['mysql', ['ALTER TABLE users ADD INDEX users_email (email), ALGORITHM=INSTANT'], ['unsupported-algorithm:users:algorithm=instant']],
            'instant index is rejected (equals optional)' => ['mariadb', ['ALTER TABLE users ADD INDEX users_email (email), ALGORITHM INSTANT'], ['unsupported-algorithm:users:algorithm=instant']],
            'inplace foreign key is rejected' => ['mysql', [$fk.', algorithm=inplace'], ['unsupported-algorithm:posts:algorithm=inplace']],
            'inplace foreign key with checks off is fine' => ['mysql', ['SET FOREIGN_KEY_CHECKS=0', $fk.', algorithm=inplace'], []],
            'lock none on fulltext is rejected' => ['mysql', ['ALTER TABLE posts ADD FULLTEXT INDEX body (body), LOCK=NONE'], ['unsupported-algorithm:posts:lock=none']],
            'lock none on create spatial index is rejected' => ['mysql', ['CREATE SPATIAL INDEX p ON places (point) LOCK=NONE'], ['unsupported-algorithm:places:lock=none']],
            'inplace lock none index is fine' => ['mysql', ['ALTER TABLE users ADD INDEX i (email), ALGORITHM=INPLACE, LOCK=NONE'], []],
            'nocopy primary key is rejected' => ['mariadb', ['ALTER TABLE users ADD PRIMARY KEY (id), ALGORITHM=NOCOPY'], ['unsupported-algorithm:users:algorithm=nocopy']],
            'nocopy index is fine' => ['mariadb', ['ALTER TABLE users ADD INDEX i (email), ALGORITHM=NOCOPY, LOCK=NONE'], []],
            'rejected even on a new table' => ['mysql', ['create table `t` (`id` int)', 'ALTER TABLE t ADD INDEX i (id), ALGORITHM=INSTANT'], ['unsupported-algorithm:t:algorithm=instant']],
        ];
    }

    /**
     * @param  list<string>  $sql
     * @param  list<string>  $expected
     */
    #[DataProvider('mysqlAlgorithmStatements')]
    public function test_mysql_table_copy_and_algorithms(string $driver, array $sql, array $expected): void
    {
        $statements = array_map(fn (string $q) => new Statement($q, $driver, 'm'), $sql);
        $findings = array_filter(
            Analyzer::withDefaultRules()->analyze($statements),
            fn (Finding $f) => in_array($f->rule, ['table-copy', 'unsupported-algorithm'], true),
        );

        $this->assertSame($expected, array_values(array_map(fn (Finding $f) => $f->rule.':'.$f->subject, $findings)));
    }

    public function test_mysql_messages_name_the_server(): void
    {
        $findings = Analyzer::withDefaultRules()->analyze([
            new Statement('ALTER TABLE users ADD PRIMARY KEY (id), ALGORITHM=NOCOPY', 'mariadb', 'm'),
            new Statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4', 'mysql', 'm'),
        ]);

        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertStringContainsString('MariaDB rejects ALGORITHM=NOCOPY for adding a primary key on [users]', $findings[0]->message);
        $this->assertSame(Severity::Warning, $findings[1]->severity);
        $this->assertStringContainsString('ALGORITHM=INPLACE (or INSTANT) and LOCK=NONE: MySQL', (string) $findings[1]->suggestion);
    }

    public function test_tables_created_in_the_same_run_are_not_flagged(): void
    {
        $rules = $this->rules([
            new Statement('create table "teams" ("id" serial primary key not null, "name" varchar(255) not null)', 'pgsql', 'a'),
            new Statement('create index "teams_name_index" on "teams" ("name")', 'pgsql', 'a'),
            new Statement('alter table "teams" add column "slug" varchar(255) not null', 'pgsql', 'b'),
            new Statement('alter table "teams" drop column "name"', 'pgsql', 'b'),
            new Statement('alter table "teams" rename to "squads"', 'pgsql', 'b'),
            new Statement('drop table "squads"', 'pgsql', 'c'),
        ]);

        $this->assertSame([], $rules);
    }

    public function test_sqlite_table_rebuild_is_reported_once_as_a_rebuild(): void
    {
        $rules = $this->rules([
            new Statement('create table "__temp__users" ("id" integer primary key autoincrement not null, "name" varchar not null)', 'sqlite', 'm'),
            new Statement('insert into "__temp__users" ("id", "name") select "id", "name" from "users"', 'sqlite', 'm'),
            new Statement('drop table "users"', 'sqlite', 'm'),
            new Statement('alter table "__temp__users" rename to "users"', 'sqlite', 'm'),
        ]);

        $this->assertSame(['table-rebuild'], $rules);
    }

    public function test_mysql_not_null_add_is_only_a_warning(): void
    {
        $findings = Analyzer::withDefaultRules()->analyze([new Statement('alter table `users` add `nickname` varchar(255) not null', 'mysql')]);

        $this->assertSame(Severity::Warning, $findings[0]->severity);
    }

    public function test_severity_can_be_overridden_or_disabled(): void
    {
        $analyzer = Analyzer::withDefaultRules(['drop-column' => 'warning', 'drop-table' => false]);

        $findings = $analyzer->analyze([
            new Statement('alter table "users" drop column "name"', 'pgsql'),
            new Statement('drop table "posts"', 'pgsql'),
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('drop-column', $findings[0]->rule);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
    }

    public function test_several_statements_in_one_string_are_split(): void
    {
        $this->assertSame(
            ["UPDATE users SET bio = 'a; b'", 'ALTER TABLE "x;y" DROP COLUMN z', '/* note; */ DELETE FROM logs'],
            Statement::splitStatements("UPDATE users SET bio = 'a; b'; ALTER TABLE \"x;y\" DROP COLUMN z;\n/* note; */ DELETE FROM logs;"),
        );
    }

    public function test_comments_are_stripped_but_strings_are_kept(): void
    {
        $this->assertSame(
            "UPDATE t SET a = '-- not a comment /* nor this */'",
            trim(Statement::stripComments("UPDATE t SET a = '-- not a comment /* nor this */' -- real comment")),
        );
        $this->assertSame('drop table a--b', (new Statement('/* x */ DROP TABLE "a--b"', 'pgsql'))->normalized);
    }

    public function test_postgres_function_bodies_are_not_split(): void
    {
        $function = 'CREATE FUNCTION touch() RETURNS trigger AS $$ BEGIN UPDATE users SET name = 1; RETURN NEW; END; $$ LANGUAGE plpgsql';

        $this->assertSame([$function, 'SELECT 1'], Statement::splitStatements($function.'; SELECT 1'));
        $this->assertSame([], $this->rules([new Statement($function, 'pgsql')]));
    }

    public function test_commas_inside_parentheses_and_strings_do_not_split_clauses(): void
    {
        $this->assertSame(
            ["add column a numeric(8, 2) default '1,2' not null", 'drop column b'],
            Statement::splitClauses("add column a numeric(8, 2) default '1,2' not null, drop column b"),
        );
    }

    /**
     * @param  list<Statement>  $statements
     * @return list<string>
     */
    private function rules(array $statements): array
    {
        return array_map(fn (Finding $f) => $f->rule, Analyzer::withDefaultRules()->analyze($statements));
    }
}
