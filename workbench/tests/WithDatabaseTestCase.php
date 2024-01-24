<?php

namespace Workbench\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Saucy\Core\Framework\SaucyServiceProvider;

use function Orchestra\Testbench\workbench_path;

abstract class WithDatabaseTestCase extends TestCase
{
    use RefreshDatabase, WithWorkbench;

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('saucy.directories', [__DIR__ . '/../app']);
        $app['config']->set('database.default', 'testing');
    }

    protected function getPackageProviders($app)
    {
        $app['config']->set('saucy.directories', [__DIR__ . '/../app']);
        return [SaucyServiceProvider::class];
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(workbench_path('/database/migrations'));
    }


}
