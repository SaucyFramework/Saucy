<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\StreamOptions;

final readonly class ReplaySubscriptionFactory
{
    public static function createReplaySubscription(
        AllStreamSubscription $original,
        SupportsBackgroundReplay $replayProjector,
    ): AllStreamSubscription {
        $replayProjector->activateReplayMode();

        return new AllStreamSubscription(
            subscriptionId: self::replaySubscriptionId($original->subscriptionId),
            streamOptions: new StreamOptions(
                pageSize: $original->streamOptions->pageSize,
                commitBatchSize: $original->streamOptions->commitBatchSize,
                eventTypes: $original->streamOptions->eventTypes,
                startingFromPosition: 0,
                processTimeoutInSeconds: $original->streamOptions->processTimeoutInSeconds,
                keepProcessingWithoutNewMessagesBeforeStopInSeconds: $original->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds,
                sleepWhenNoNewMessagesBeforeRetryInMicroseconds: $original->streamOptions->sleepWhenNoNewMessagesBeforeRetryInMicroseconds,
                queue: $original->streamOptions->queue,
            ),
            messageConsumer: $replayProjector,
            eventReader: $original->eventReader,
            eventSerializer: $original->eventSerializer,
            checkpointStore: $original->checkpointStore,
            streamNameTypeMap: $original->streamNameTypeMap,
            activityStreamLogger: $original->activityStreamLogger,
            failureMode: FailureMode::Halt,
        );
    }

    public static function replaySubscriptionId(string $originalId): string
    {
        return 'replay__' . $originalId;
    }
}
