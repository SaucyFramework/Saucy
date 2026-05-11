<?php

namespace Saucy\Core\Subscriptions\Infra;

use EventSauce\EventSourcing\UnableToInflectClassName;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Saucy\Core\Projections\ProjectorConfig;
use Saucy\Core\Projections\ProjectorMap;
use Saucy\Core\Projections\ProjectorType;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\MigratingKeyCheckpointStore;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscription;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Saucy\MessageStorage\StreamReader;

final readonly class SubscriptionRegistryFactory
{
    /**
     * @param ProjectorMap $projectorMap
     * @param Application $application
     * @param TypeMap $typeMap
     * @return array<StreamSubscription|AllStreamSubscription>
     */
    public static function buildForProjectorMap(ProjectorMap $projectorMap, Application $application, TypeMap $typeMap): array
    {
        $streams = [];
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig) {
            $streams[] = match ($projectorConfig->projectorType) {
                ProjectorType::AllStream => self::buildAllStreamSubscription($projectorConfig, $typeMap, $application),
                ProjectorType::AggregateInstance => self::buildStreamSubscription($projectorConfig, $typeMap, $application),
            };
        }
        return $streams;
    }

    /**
     * @return array<string>
     */
    private static function mapEventTypes(TypeMap $typeMap, ProjectorConfig $projectorConfig): array
    {
        return array_filter(
            array_map(function (string $className) use ($typeMap) {
                try {
                    return $typeMap->classNameToType($className);
                } catch (UnableToInflectClassName $e) {
                    return false;
                }
            }, $projectorConfig->handlingEventClasses),
        );
    }

    private static function resolveSubscriptionId(ProjectorConfig $projectorConfig): string
    {
        return $projectorConfig->name ?? (string) Str::of($projectorConfig->projectorClass)->afterLast('\\')->snake();
    }

    /**
     * Computes the old FQN-based subscription ID that aggregate projectors used before.
     */
    private static function legacyStreamSubscriptionId(ProjectorConfig $projectorConfig): string
    {
        return (string) Str::of($projectorConfig->projectorClass)->snake();
    }

    private static function buildAllStreamSubscription(ProjectorConfig $projectorConfig, TypeMap $typeMap, Application $application): AllStreamSubscription
    {
        $subscriptionId = self::resolveSubscriptionId($projectorConfig);
        $checkpointStore = $application->make(CheckpointStore::class);

        // If a custom name was provided, migrate from the old auto-generated name
        if ($projectorConfig->name !== null) {
            $oldId = (string) Str::of($projectorConfig->projectorClass)->afterLast('\\')->snake();
            if ($oldId !== $subscriptionId) {
                $checkpointStore = new MigratingKeyCheckpointStore($checkpointStore, $oldId, $subscriptionId);
            }
        }

        return new AllStreamSubscription(
            subscriptionId: $subscriptionId,
            streamOptions: new StreamOptions(
                pageSize: $projectorConfig->pageSize ?? config('saucy.all_stream_projection.page_size', 10), // @phpstan-ignore-line
                commitBatchSize: $projectorConfig->commitBatchSize ?? config('saucy.all_stream_projection.commit_batch_size', 1), // @phpstan-ignore-line
                eventTypes: self::mapEventTypes($typeMap, $projectorConfig),
                startingFromPosition: $projectorConfig->startFrom,
                processTimeoutInSeconds: config('saucy.all_stream_projection.timeout'), // @phpstan-ignore-line
                keepProcessingWithoutNewMessagesBeforeStopInSeconds: config('saucy.all_stream_projection.keep_processing_without_new_messages_before_stop_in_seconds'), // @phpstan-ignore-line
                queue: config('saucy.all_stream_projection.queue'), // @phpstan-ignore-line
                visibilityDelayMs: $projectorConfig->visibilityDelayMs,
            ),
            messageConsumer: $application->make($projectorConfig->projectorClass),
            eventReader: $application->make(AllStreamReader::class),
            eventSerializer: new ConstructingPayloadSerializer($typeMap),
            checkpointStore: $checkpointStore,
            streamNameTypeMap: $typeMap,
            activityStreamLogger: $application->make(ActivityStreamLogger::class),
            failureMode: $projectorConfig->failureMode,
            poisonMessageStore: $application->make(PoisonMessageStore::class),
            poisonMessageRecorder: $application->make(PoisonMessageRecorder::class),
        );
    }

    private static function buildStreamSubscription(ProjectorConfig $projectorConfig, TypeMap $typeMap, Application $application): StreamSubscription
    {
        if ($projectorConfig->aggregateType === null) {
            throw new \Exception('Aggregate type is required for aggregate instance projectors');
        }

        $subscriptionId = self::resolveSubscriptionId($projectorConfig);
        $checkpointStore = $application->make(CheckpointStore::class);

        // Migrate from old FQN-based subscription ID to new short name
        $oldId = self::legacyStreamSubscriptionId($projectorConfig);
        if ($oldId !== $subscriptionId) {
            $checkpointStore = new MigratingKeyCheckpointStore($checkpointStore, $oldId, $subscriptionId);
        }

        return new StreamSubscription(
            subscriptionId: $subscriptionId,
            aggregateType: $projectorConfig->aggregateType,
            streamOptions: new StreamOptions(
                eventTypes: self::mapEventTypes($typeMap, $projectorConfig),
                processTimeoutInSeconds: config('saucy.stream_projection.timeout'), // @phpstan-ignore-line
                keepProcessingWithoutNewMessagesBeforeStopInSeconds: config('saucy.stream_projection.keep_processing_without_new_messages_before_stop_in_seconds'), // @phpstan-ignore-line
                queue: config('saucy.stream_projection.queue'), // @phpstan-ignore-line
            ),
            messageConsumer: $application->make($projectorConfig->projectorClass),
            eventReader: $application->make(StreamReader::class),
            eventSerializer: new ConstructingPayloadSerializer($typeMap),
            checkpointStore: $checkpointStore,
            streamNameTypeMap: $typeMap,
            failureMode: $projectorConfig->failureMode,
            poisonMessageRecorder: $application->make(PoisonMessageRecorder::class),
            activityStreamLogger: $application->make(ActivityStreamLogger::class),
        );
    }

    /**
     * @param ProjectorMap $projectorMap
     * @param Application $application
     * @param TypeMap $typeMap
     * @return array<AllStreamSubscription>
     */
    public static function buildAllStreamSubscriptionForProjectorMap(ProjectorMap $projectorMap, Application $application, TypeMap $typeMap): array
    {
        $streams = [];
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig) {
            if ($projectorConfig->projectorType === ProjectorType::AllStream) {
                $streams[] = self::buildAllStreamSubscription($projectorConfig, $typeMap, $application);
            }
        }
        return $streams;
    }

    /**
     * @param ProjectorMap $projectorMap
     * @param Application $application
     * @param TypeMap $typeMap
     * @return array<StreamSubscription>
     */
    public static function buildStreamSubscriptionForProjectorMap(ProjectorMap $projectorMap, Application $application, TypeMap $typeMap): array
    {
        $streams = [];
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig) {
            if ($projectorConfig->projectorType === ProjectorType::AggregateInstance && $projectorConfig->async) {
                $streams[] = self::buildStreamSubscription($projectorConfig, $typeMap, $application);
            }
        }
        return $streams;
    }

    /**
     * @param ProjectorMap $projectorMap
     * @param Application $application
     * @param TypeMap $typeMap
     * @return array<StreamSubscription>
     */
    public static function buildSyncStreamSubscriptionForProjectorMap(ProjectorMap $projectorMap, Application $application, TypeMap $typeMap): array
    {
        $streams = [];
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig) {
            if ($projectorConfig->projectorType === ProjectorType::AggregateInstance && !$projectorConfig->async) {
                $streams[] = self::buildStreamSubscription($projectorConfig, $typeMap, $application);
            }
        }
        return $streams;
    }
}
