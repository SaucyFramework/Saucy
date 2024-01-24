<?php

namespace Saucy\Core\Framework;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use League\ConstructFinder\ConstructFinder;
use Saucy\Core\Command\CommandBus;
use Saucy\Core\Command\TaskMapCommandHandler;
use Saucy\Core\Events\EventTypeMapBuilder;
use Saucy\Core\Events\Streams\AggregateRootStreamNameMapper;
use Saucy\Core\Events\Streams\StreamNameMapper;
use Saucy\Core\EventSourcing\CommandHandling\EventSourcingCommandMapBuilder;
use Saucy\Core\EventSourcing\TypeMap\AggregateRootTypeMapBuilder;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\ConstructingPayloadSerializer;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\Tasks\TaskRunner;
use Workbench\App\BankAccount\BankAccountAggregate;
use Workbench\App\BankAccount\Events\AccountCredited;


final class SaucyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/saucy.php' => config_path('saucy.php'),
        ]);
    }
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/saucy.php', 'saucy'
        );

        $classes = ConstructFinder::locatedIn(...config('saucy.directories'))->findClassNames();

        // build type map
        $typeMap = TypeMap::of(
            AggregateRootTypeMapBuilder::make()->create($classes),
            EventTypeMapBuilder::make()->create($classes),
        );

        $this->app->instance(TypeMap::class, $typeMap);

        $this->app->bind(AllStreamMessageRepository::class, function (Application $application){
            return new IlluminateMessageStorage(
                $application->make(DatabaseManager::class)->connection(),
                new ConstructingPayloadSerializer($application->make(TypeMap::class)),
                'event_store',
            );
        });

        $this->app->instance(StreamNameMapper::class, new AggregateRootStreamNameMapper());

        $commandTaskMap = EventSourcingCommandMapBuilder::buildTaskMapForClasses($classes);

        $this->app->instance(CommandBus::class,
            new CommandBus(
                new TaskMapCommandHandler(
                    commandTaskMap: $commandTaskMap,
                    taskRunner: new TaskRunner($this->app)
                ),
            )
        );
    }
}
