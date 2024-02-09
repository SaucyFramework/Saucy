<?php

namespace Workbench\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Saucy\Core\Framework\SaucyServiceProvider;

use Workbench\App\Providers\WorkbenchServiceProvider;

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
        $app['config']->set('saucy.stream_projection.keep_processing_without_new_messages_before_stop_in_seconds', 0);
        $app['config']->set('saucy.all_stream_projection.keep_processing_without_new_messages_before_stop_in_seconds', 0);
        $app['config']->set('database.default', 'testing');
    }

    protected function getPackageProviders($app)
    {
        $app['config']->set('saucy.directories', [__DIR__ . '/../app']);
        return [SaucyServiceProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(workbench_path('/database/migrations'));
    }


}
