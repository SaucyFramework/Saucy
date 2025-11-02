<?php

namespace Saucy\Core\Framework;

use EventSauce\BackOff\BackOffStrategy;
use EventSauce\BackOff\ExponentialBackOffStrategy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Saucy\Core\Command\CommandBus;
use Saucy\Core\Command\TaskMapCommandHandler;
use Saucy\Core\Events\Streams\AggregateRootStreamNameMapper;
use Saucy\Core\Events\Streams\StreamNameMapper;
use Saucy\Core\Laravel\Commands\BuildSaucyCache;
use Saucy\Core\Laravel\Commands\EnsureDynamoDbTables;
use Saucy\Core\Projections\AwaitProjected;
use Saucy\Core\Query\QueryBus;
use Saucy\Core\Query\QueryHandlingMiddleware;
use Saucy\Core\Query\SelfHandlingQueryHandler;
use Saucy\Core\Serialisation\TypeMap;
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
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\Core\EventSourcing\AggregateStore;
use Saucy\Core\EventSourcing\EventStoreRegistry;
use Saucy\Core\Tracing\TracePersistedEventsHook;
use Saucy\Core\Tracing\Tracer;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Hooks\Hooks;
use Saucy\MessageStorage\HooksMessageStore;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Saucy\MessageStorage\Serialization\EventSerializer;
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

        $this->commands([
            BuildSaucyCache::class,
            EnsureDynamoDbTables::class,
        ]);

        // Create hooks and replace default store
        // Subscriptions resolve stores lazily, so stores can be registered at any time during boot
        $eventStoreRegistry = $this->app->make(EventStoreRegistry::class);
        $messageRepository = $this->app->make('saucy.message_repository');
        $defaultStoreId = $this->app->make('saucy.default_store_id');

        $defaultStoreWithHooks = new HooksMessageStore(
            $messageRepository,
            new Hooks(
                $this->app->make(TriggerSubscriptionProcessesAfterPersist::class),
                $this->app->make(PlaySynchronousProjectorsAfterPersist::class),
                $this->app->make(TracePersistedEventsHook::class),
            ),
        );

        // Replace the default store with the one that has hooks
        $eventStoreRegistry->register(
            id: $defaultStoreId,
            store: $defaultStoreWithHooks,
            streamReader: $messageRepository,
            allStreamReader: $messageRepository,
            readEventData: $messageRepository,
        );
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/saucy.php',
            'saucy',
        );

        $this->app->bind(BuildSaucyProjectMappings::class, fn() => new BuildSaucyProjectMappings(config('saucy.cache_path')));

        $builder = $this->app->make(BuildSaucyProjectMappings::class);
        /** @var SaucyProjectMaps $saucyProjectMaps */
        $saucyProjectMaps = $builder->get(forceNew: config('app.env') === 'local');

        $typeMap = $saucyProjectMaps->typeMap;

        $this->app->instance(RunAllSubscriptionsInSync::class, new RunAllSubscriptionsInSync(
            runSync: config('app.env') === 'testing',
        ));

        $this->app->instance(TypeMap::class, $typeMap);

        $this->app->instance(StreamNameMapper::class, new AggregateRootStreamNameMapper());

        $this->app->instance(EventSerializer::class, new ConstructingPayloadSerializer($this->app->make(TypeMap::class)));

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

        // Create EventStoreRegistry
        $eventStoreRegistry = new EventStoreRegistry();
        $this->app->instance(EventStoreRegistry::class, $eventStoreRegistry);

        // Register default event store (without hooks initially, hooks depend on subscription registries)
        $messageRepository = new IlluminateMessageStorage(
            connection: $this->app->make(DatabaseManager::class)->connection(),
            eventSerializer: new ConstructingPayloadSerializer($this->app->make(TypeMap::class)),
            streamNameTypeMap: $typeMap,
            tableName: 'event_store',
        );

        $defaultStoreId = config('saucy.default_event_store', 'default');
        // Register the basic store first (subscriptions need it to exist)
        $eventStoreRegistry->register(
            id: $defaultStoreId,
            store: $messageRepository,
            streamReader: $messageRepository,
            allStreamReader: $messageRepository,
            readEventData: $messageRepository,
        );
        $eventStoreRegistry->setDefault($defaultStoreId);

        // Backward compatibility: bind default store implementations
        $this->app->bind(ReadEventData::class, fn() => $messageRepository);
        $this->app->bind(AllStreamReader::class, fn() => $eventStoreRegistry->getAllStreamReader(null));
        $this->app->bind(StreamReader::class, fn() => $eventStoreRegistry->getStreamReader(null));

        // Bind AggregateStore to use EventStoreRegistry
        $this->app->bind(AggregateStore::class, function (Application $application) use ($eventStoreRegistry, $saucyProjectMaps) {
            return new AggregateStore(
                eventStoreRegistry: $eventStoreRegistry,
                streamNameMapper: $application->make(StreamNameMapper::class),
                typeMap: $application->make(TypeMap::class),
                aggregateEventStoreMap: $saucyProjectMaps->aggregateEventStoreMap,
            );
        });

        $this->app->when(AwaitProjected::class)->needs(BackOffStrategy::class)->give(fn() => new ExponentialBackOffStrategy(500, 10000, 50000, 2));

        $projectorMap = $saucyProjectMaps->projectorMap;

        // Bind subscription registries (they can now use the default store)
        $this->app->bind(AllStreamSubscriptionRegistry::class, fn(Application $application) => new AllStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildAllStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->bind(StreamSubscriptionRegistry::class, fn(Application $application) => new StreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->bind(SyncStreamSubscriptionRegistry::class, fn(Application $application) => new SyncStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildSyncStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        // Defer hook creation to boot() method so all service providers have registered their bindings first
        // Store the messageRepository and eventStoreRegistry for use in boot()
        $this->app->instance('saucy.message_repository', $messageRepository);
        $this->app->instance('saucy.default_store_id', $defaultStoreId);

        // Add helper method for registering additional event stores
        // Users can call: app(EventStoreRegistry::class)->register(...)

        // Bind AllStreamMessageRepository to default store with hooks for backward compatibility
        $this->app->bind(AllStreamMessageRepository::class, fn() => $eventStoreRegistry->getDefault());

        $commandTaskMap = $saucyProjectMaps->commandTaskMap;

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
                    $saucyProjectMaps->queryMap,
                ),
            ),
        );


    }
}
