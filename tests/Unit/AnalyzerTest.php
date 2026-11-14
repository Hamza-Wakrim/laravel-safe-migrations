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
            'add auto increment' => ['mysql', 'alter table `users` add `id` bigint unsigned not null auto_increment primary key', []],
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
        ];
    }

    #[DataProvider('statements')]
    public function test_it_flags_statements(string $driver, string $sql, array $expected): void
    {
        $this->assertSame($expected, $this->rules([new Statement($sql, $driver, 'm')]));
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
