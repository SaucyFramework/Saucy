<?php

namespace Workbench\Tests\Worker;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Saucy's own tables, on in-memory SQLite by default. Set SAUCY_TEST_MYSQL_HOST (and optionally
 * SAUCY_TEST_MYSQL_PORT/DATABASE/USERNAME/PASSWORD) to run against MySQL instead; the gap-probe
 * tests only run there, because `FOR SHARE NOWAIT` and auto-increment holes are MySQL behaviour.
 */
abstract class WorkerTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $host = getenv('SAUCY_TEST_MYSQL_HOST');
        if (is_string($host) && $host !== '') {
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => $host,
                'port' => getenv('SAUCY_TEST_MYSQL_PORT') ?: '3306',
                'database' => getenv('SAUCY_TEST_MYSQL_DATABASE') ?: 'saucy_test',
                'username' => getenv('SAUCY_TEST_MYSQL_USERNAME') ?: 'root',
                'password' => getenv('SAUCY_TEST_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]);
            $app['config']->set('database.default', 'mysql');

            return;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        // Registered with the migrator rather than loadMigrationsFrom(): RefreshDatabase then runs
        // migrate:fresh, and testbench never rolls the migrations back (a down() that drops a
        // column after its table is gone fails on MySQL).
        $this->app?->make('migrator')->path(__DIR__ . '/../../../migrations');
    }

    protected function usesMysql(): bool
    {
        return config('database.default') === 'mysql';
    }
}
