<?php

namespace HamzaWakrim\SafeMigrations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use HamzaWakrim\SafeMigrations\SafeMigrationsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SafeMigrationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('SAFE_MIGRATIONS_MYSQL_HOST'),
            'port' => env('SAFE_MIGRATIONS_MYSQL_PORT', 3306),
            'database' => env('SAFE_MIGRATIONS_DB_NAME', 'safe_migrations'),
            'username' => env('SAFE_MIGRATIONS_DB_USER', 'root'),
            'password' => env('SAFE_MIGRATIONS_DB_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        $config->set('database.connections.mariadb', [
            'driver' => 'mariadb',
            'host' => env('SAFE_MIGRATIONS_MARIADB_HOST'),
            'port' => env('SAFE_MIGRATIONS_MARIADB_PORT', 3306),
            'database' => env('SAFE_MIGRATIONS_DB_NAME', 'safe_migrations'),
            'username' => env('SAFE_MIGRATIONS_DB_USER', 'root'),
            'password' => env('SAFE_MIGRATIONS_DB_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        $config->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('SAFE_MIGRATIONS_PGSQL_HOST'),
            'port' => env('SAFE_MIGRATIONS_PGSQL_PORT', 5432),
            'database' => env('SAFE_MIGRATIONS_DB_NAME', 'safe_migrations'),
            'username' => env('SAFE_MIGRATIONS_DB_USER', 'root'),
            'password' => env('SAFE_MIGRATIONS_DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);
    }

    protected function fixture(string $name): string
    {
        return __DIR__.'/fixtures/'.$name;
    }
}
