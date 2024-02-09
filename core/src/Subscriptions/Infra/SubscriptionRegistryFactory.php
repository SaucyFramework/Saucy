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
use Saucy\Core\Subscriptions\ConsumePipe;
use Saucy\Core\Subscriptions\MessageConsumption\HandlerFilter;
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
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig){
            $streams[] = match ($projectorConfig->projectorType){
                ProjectorType::AllStream => self::buildAllStreamSubscription($projectorConfig, $typeMap, $application),
                ProjectorType::AggregateInstance => self::buildStreamSubscription($projectorConfig, $typeMap, $application)
            };
        }
        return $streams;
    }

    private static function mapEventTypes(TypeMap $typeMap, ProjectorConfig $projectorConfig): array
    {
        return array_filter(
            array_map(function (string $className) use ($typeMap) {
                try {
                    return $typeMap->classNameToType($className);
                } catch (UnableToInflectClassName $e){
                    return false;
                }
            }, $projectorConfig->handlingEventClasses)
        );
    }

    private static function buildAllStreamSubscription(ProjectorConfig $projectorConfig, TypeMap $typeMap, Application $application): AllStreamSubscription
    {
        return new AllStreamSubscription(
            subscriptionId: Str::of($projectorConfig->projectorClass)->snake(),
            streamOptions: new StreamOptions(
                eventTypes: self::mapEventTypes($typeMap, $projectorConfig),
                processTimeoutInSeconds: config('saucy.all_stream_projection.timeout'),
                keepProcessingWithoutNewMessagesBeforeStopInSeconds: config('saucy.all_stream_projection.keep_processing_without_new_messages_before_stop_in_seconds'),
                queue: config('saucy.all_stream_projection.queue'),
            ),
            consumePipe: new ConsumePipe(
                new HandlerFilter(
                    $application->make($projectorConfig->projectorClass),
                )
            ),
            eventReader: $application->make(AllStreamReader::class),
            eventSerializer: new ConstructingPayloadSerializer($typeMap),
            checkpointStore: $application->make(CheckpointStore::class),
            streamNameTypeMap: $typeMap,
        );
    }

    private static function buildStreamSubscription(ProjectorConfig $projectorConfig, TypeMap $typeMap, Application $application): StreamSubscription
    {
        return new StreamSubscription(
            subscriptionId: Str::of($projectorConfig->projectorClass)->snake(),
            aggregateType: $projectorConfig->aggregateType,
            streamOptions: new StreamOptions(
                eventTypes: self::mapEventTypes($typeMap, $projectorConfig),
                processTimeoutInSeconds: config('saucy.stream_projection.timeout'),
                keepProcessingWithoutNewMessagesBeforeStopInSeconds: config('saucy.stream_projection.keep_processing_without_new_messages_before_stop_in_seconds'),
                queue: config('saucy.stream_projection.queue'),
            ),
            consumePipe: new ConsumePipe(
                new HandlerFilter(
                    $application->make($projectorConfig->projectorClass),
                )
            ),
            eventReader: $application->make(StreamReader::class),
            eventSerializer: new ConstructingPayloadSerializer($typeMap),
            checkpointStore: $application->make(CheckpointStore::class),
            streamNameTypeMap: $typeMap,
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
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig){
            if($projectorConfig->projectorType === ProjectorType::AllStream){
                $streams[] = self::buildAllStreamSubscription($projectorConfig, $typeMap, $application);
            }
        }
        return $streams;
    }

    /**
     * @param ProjectorMap $projectorMap
     * @param Application $application
     * @param TypeMap $typeMap
     * @return array<AllStreamSubscription>
     */
    public static function buildStreamSubscriptionForProjectorMap(ProjectorMap $projectorMap, Application $application, TypeMap $typeMap): array
    {
        $streams = [];
        foreach ($projectorMap->getProjectorConfigs() as $projectorConfig){
            if($projectorConfig->projectorType === ProjectorType::AggregateInstance){
                $streams[] = self::buildStreamSubscription($projectorConfig, $typeMap, $application);
            }
        }
        return $streams;
    }
}
