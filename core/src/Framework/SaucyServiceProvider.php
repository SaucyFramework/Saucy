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
use Saucy\Core\Laravel\Commands\BackfillAggregateInstancesCommand;
use Saucy\Core\Laravel\Commands\BuildSaucyCache;
use Saucy\Core\Laravel\Commands\PoisonMessagesCommand;
use Saucy\Core\Laravel\Commands\SnapshotPositionsCommand;
use Saucy\Core\Projections\AwaitProjected;
use Saucy\Core\Projections\Replay\BackgroundReplayManager;
use Saucy\Core\Projections\Replay\BackgroundReplayStore;
use Saucy\Core\Projections\Replay\IlluminateBackgroundReplayStore;
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
use Saucy\Core\Subscriptions\Metrics\IlluminateProjectionSnapshotStore;
use Saucy\Core\Subscriptions\Metrics\ProjectionSnapshotStore;
use Saucy\Core\Subscriptions\PoisonMessages\IlluminatePoisonMessageStore;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageManager;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Saucy\Core\Subscriptions\StreamSubscription\AggregateInstanceRepository;
use Saucy\Core\Subscriptions\StreamSubscription\IlluminateAggregateInstanceRepository;
use Saucy\Core\Subscriptions\StreamSubscription\RecordAggregateInstancesAfterPersist;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionReplayManager;
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
            BackfillAggregateInstancesCommand::class,
            BuildSaucyCache::class,
            PoisonMessagesCommand::class,
            SnapshotPositionsCommand::class,
        ]);
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

        $this->app->scoped(Tracer::class, fn() => new Tracer());

        $this->app->singleton(RunningProcesses::class, function (Application $application) {
            return new IlluminateRunningProcesses(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->singleton(CheckpointStore::class, function (Application $application) {
            return new IlluminateCheckpointStore(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->singleton(ActivityStreamLogger::class, function (Application $application) {
            return $application->make(IlluminateActivityStreamLogger::class);
        });

        $this->app->singleton(ProjectionSnapshotStore::class, function (Application $application) {
            return $application->make(IlluminateProjectionSnapshotStore::class);
        });

        $this->app->singleton(PoisonMessageStore::class, function (Application $application) {
            return new IlluminatePoisonMessageStore(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->singleton(PoisonMessageRecorder::class, function (Application $application) {
            $notifiable = null;
            /** @var class-string|null $notifiableClass */
            $notifiableClass = config('saucy.poison_messages.notification.notifiable');
            if ($notifiableClass !== null) {
                $notifiable = $application->make($notifiableClass);
            }

            return new PoisonMessageRecorder(
                $application->make(PoisonMessageStore::class),
                $notifiable,
            );
        });

        $this->app->singleton(PoisonMessageManager::class);

        $this->app->singleton(BackgroundReplayStore::class, function (Application $application) {
            return new IlluminateBackgroundReplayStore(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->singleton(BackgroundReplayManager::class);

        $this->app->singleton(AggregateInstanceRepository::class, function (Application $application) {
            return new IlluminateAggregateInstanceRepository(
                $application->make(DatabaseManager::class)->connection(),
            );
        });

        $this->app->singleton(StreamSubscriptionReplayManager::class);

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
                    $application->make(RecordAggregateInstancesAfterPersist::class),
                    $application->make(TriggerSubscriptionProcessesAfterPersist::class),
                    $application->make(PlaySynchronousProjectorsAfterPersist::class),
                    $application->make(TracePersistedEventsHook::class),
                ),
            );
        });

        $this->app->when(AwaitProjected::class)->needs(BackOffStrategy::class)->give(fn() => new ExponentialBackOffStrategy(500, 10000, 50000, 2));

        $projectorMap = $saucyProjectMaps->projectorMap;

        $this->app->singleton(AllStreamSubscriptionRegistry::class, fn(Application $application) => new AllStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildAllStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->singleton(StreamSubscriptionRegistry::class, fn(Application $application) => new StreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->singleton(SyncStreamSubscriptionRegistry::class, fn(Application $application) => new SyncStreamSubscriptionRegistry(
            ...SubscriptionRegistryFactory::buildSyncStreamSubscriptionForProjectorMap($projectorMap, $application, $typeMap),
        ));

        $this->app->instance(StreamNameMapper::class, new AggregateRootStreamNameMapper());

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

        $this->app->instance(EventSerializer::class, new ConstructingPayloadSerializer($this->app->make(TypeMap::class)));
    }
}
