<?php

namespace Saucy\Core\Framework;

use EventSauce\BackOff\BackOffStrategy;
use EventSauce\BackOff\ExponentialBackOffStrategy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use League\ConstructFinder\ConstructFinder;
use Saucy\Core\Command\CommandBus;
use Saucy\Core\Command\TaskMapCommandHandler;
use Saucy\Core\Events\EventTypeMapBuilder;
use Saucy\Core\Events\Streams\AggregateRootStreamNameMapper;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamNameMapper;
use Saucy\Core\EventSourcing\CommandHandling\EventSourcingCommandMapBuilder;
use Saucy\Core\EventSourcing\TypeMap\AggregateRootTypeMapBuilder;
use Saucy\Core\Projections\AwaitProjected;
use Saucy\Core\Projections\ProjectorMapBuilder;
use Saucy\Core\Query\QueryBus;
use Saucy\Core\Query\QueryHandlerMapBuilder;
use Saucy\Core\Query\QueryHandlingMiddleware;
use Saucy\Core\Query\SelfHandlingQueryHandler;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\IlluminateCheckpointStore;
use Saucy\Core\Subscriptions\Infra\IlluminateRunningProcesses;
use Saucy\Core\Subscriptions\Infra\PlaySynchronousProjectorsAfterPersist;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\SubscriptionRegistryFactory;
use Saucy\Core\Subscriptions\Infra\TriggerSubscriptionProcessesAfterPersist;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\IlluminateActivityStreamLogger;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\Core\Tracing\TracePersistedEventsHook;
use Saucy\Core\Tracing\Tracer;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Hooks\Hooks;
use Saucy\MessageStorage\HooksMessageStore;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Saucy\MessageStorage\StreamReader;
use Saucy\Tasks\TaskRunner;

final class SaucyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/saucy.php' => config_path('saucy.php'),
        ]);

        $this->loadMigrationsFrom(__DIR__ . '/../../../migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/saucy.php',
            'saucy',
        );

        $classes = ConstructFinder::locatedIn(...config('saucy.directories'))
            ->exclude(...config('saucy.exclude_files', ['*Test.php', '*/Tests/*', '*TestCase.php']))
            ->findClassNames(); // @phpstan-ignore-line

        // build type map
        $typeMap = TypeMap::of(
            AggregateRootTypeMapBuilder::make()->create($classes),
            EventTypeMapBuilder::make()->create($classes),
            new TypeMap([
                AggregateStreamName::class => 'aggregate_stream_name',
            ]),
        );

        $this->app->instance(RunAllSubscriptionsInSync::class, new RunAllSubscriptionsInSync(
            runSync: config('app.env') === 'testing',
        ));

        $this->app->instance(TypeMap::class, $typeMap);

        $this->app->scoped(Tracer::class, fn() => new Tracer());

        $this->app->bind(RunningProcesses::class, function (Application $application) {
            return new IlluminateRunningProcesses(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->bind(CheckpointStore::class, function (Application $application) {
            return new IlluminateCheckpointStore(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->bind(ActivityStreamLogger::class, function (Application $application) {
            return $application->make(IlluminateActivityStreamLogger::class);
        });

        $messageRepository = new IlluminateMessageStorage(
            connection: $this->app->make(DatabaseManager::class)->connection(),
            eventSerializer: new ConstructingPayloadSerializer($this->app->make(TypeMap::class)),
            streamNameTypeMap: $typeMap,
            tableName: 'event_store',
        );

        $this->app->bind(ReadEventData::class, fn() => $messageRepository);
        $this->app->bind(AllStreamReader::class, fn() => $messageRepository);
        $this->app->bind(StreamReader::class, fn() => $messageRepository);

        $this->app->bind(AllStreamMessageRepository::class, function (Application $application) use ($messageRepository) {
            return new HooksMessageStore(
                $messageRepository,
                new Hooks(
                    $application->make(TriggerSubscriptionProcessesAfterPersist::class),
                    $application->make(PlaySynchronousProjectorsAfterPersist::class),
                    $application->make(TracePersistedEventsHook::class),
                ),
            );
        });

        $this->app->when(AwaitProjected::class)->needs(BackOffStrategy::class)->give(fn() => new ExponentialBackOffStrategy(500, 10000, 50000, 2));

        // auto wire stream subscriptions
        $projectorMap = ProjectorMapBuilder::buildForClasses($classes, $typeMap);

        $this->app->bind(AllStreamSubscriptionRegistry::class, fn(Application $application) => new AllStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildAllStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->bind(StreamSubscriptionRegistry::class, fn(Application $application) => new StreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->bind(SyncStreamSubscriptionRegistry::class, fn(Application $application) => new SyncStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildSyncStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->instance(StreamNameMapper::class, new AggregateRootStreamNameMapper());

        $commandTaskMap = EventSourcingCommandMapBuilder::buildTaskMapForClasses($classes);

        $this->app->instance(
            CommandBus::class,
            new CommandBus(
                new TaskMapCommandHandler(
                    commandTaskMap: $commandTaskMap,
                    taskRunner: new TaskRunner($this->app),
                ),
            ),
        );

        $this->app->instance(
            QueryBus::class,
            new QueryBus(
                new SelfHandlingQueryHandler(),
                new QueryHandlingMiddleware(
                    new TaskRunner($this->app),
                    QueryHandlerMapBuilder::buildQueryMapForClasses($classes),
                ),
            ),
        );
    }
}
