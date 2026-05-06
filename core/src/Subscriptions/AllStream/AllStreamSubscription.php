<?php

namespace Saucy\Core\Subscriptions\AllStream;

use DateTimeImmutable;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Gaps\Gap;
use Saucy\Core\Subscriptions\Gaps\GapStore;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatResetsBeforeReplay;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\SubscriptionActivity;
use Saucy\Core\Subscriptions\PoisonMessages\EventHandlerWithRetry;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\MessageStorage\StoredEvent;

final readonly class AllStreamSubscription
{
    public function __construct(
        public string $subscriptionId,
        public StreamOptions $streamOptions,
        public MessageConsumer $messageConsumer,
        public AllStreamReader $eventReader,
        public EventSerializer $eventSerializer,
        public CheckpointStore $checkpointStore,
        public GapStore $gapStore,
        public TypeMap $streamNameTypeMap,
        public ActivityStreamLogger $activityStreamLogger,
        public FailureMode $failureMode = FailureMode::Halt,
        public ?PoisonMessageStore $poisonMessageStore = null,
        public ?PoisonMessageRecorder $poisonMessageRecorder = null,
    ) {}

    public function isUpToDate(?int $upToPosition): bool
    {
        try {
            $checkpoint = $this->checkpointStore->get($this->subscriptionId);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        }

        if ($upToPosition !== null && $checkpoint->position >= $upToPosition) {
            return true;
        }

        $storedEvents = $this->eventReader->paginate(
            new AllStreamQuery(
                fromPosition: $checkpoint->position,
                limit: 1,
            ),
        );

        // Any event past the checkpoint counts as "more work" — even
        // events the projector filters out, since they must be polled to
        // advance the checkpoint.
        foreach ($storedEvents as $storedEvent) {
            if ($upToPosition !== null && $storedEvent->globalPosition > $upToPosition) {
                break;
            }
            return false;
        }

        return true;
    }

    public function poll(int $timeoutInSeconds = 100): int
    {
        $log = [];
        $startTime = time();
        $now = new DateTimeImmutable('now');

        $this->appendToActivity($log, 'started_poll', 'started poll', [
            'timeout' => $timeoutInSeconds,
            'start_time' => $startTime,
        ]);

        try {
            $checkpoint = $this->checkpointStore->get($this->subscriptionId);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        }

        $openGaps = $this->gapStore->getOpen($this->subscriptionId);

        $expiredGapPositions = [];
        $unexpiredGapPositions = [];
        foreach ($openGaps as $gap) {
            if ($gap->isExpired($now, $this->streamOptions->gapTimeoutSeconds)) {
                $expiredGapPositions[] = $gap->position;
            } else {
                $unexpiredGapPositions[] = $gap->position;
            }
        }

        // Re-check unexpired gaps: their writer transactions may have committed.
        $resolvedGapEvents = [];
        if ($unexpiredGapPositions !== []) {
            foreach ($this->eventReader->fetchByGlobalPositions($unexpiredGapPositions) as $event) {
                $resolvedGapEvents[$event->globalPosition] = $event;
            }
        }
        $resolvedGapPositions = array_keys($resolvedGapEvents);

        $processBatches = $this->messageConsumer instanceof MessageConsumerThatHandlesBatches;
        $pageLimit = $processBatches ? $this->messageConsumer->getBatchSize() : $this->streamOptions->pageSize;

        $pageEvents = [];
        $pageEventPositions = [];
        foreach ($this->eventReader->paginate(new AllStreamQuery(
            fromPosition: $checkpoint->position,
            limit: $pageLimit,
        )) as $event) {
            $pageEvents[] = $event;
            $pageEventPositions[$event->globalPosition] = true;
        }

        $maxSeenInPage = $pageEvents !== [] ? end($pageEvents)->globalPosition : null;
        reset($pageEvents);

        // Detect new gaps: positions in [checkpoint+1, maxSeenInPage] that
        // didn't appear in the page (and aren't already tracked).
        $newGapPositions = [];
        if ($maxSeenInPage !== null) {
            $alreadyTracked = array_flip($unexpiredGapPositions);
            for ($pos = $checkpoint->position + 1; $pos < $maxSeenInPage; $pos++) {
                if (isset($pageEventPositions[$pos])) {
                    continue;
                }
                if (isset($alreadyTracked[$pos])) {
                    continue;
                }
                if (isset($resolvedGapEvents[$pos])) {
                    continue;
                }
                $newGapPositions[] = $pos;
            }
        }

        $this->appendToActivity($log, 'loaded_events', 'loaded events', [
            'fromPosition' => $checkpoint->position,
            'pageSize' => $pageLimit,
            'pageCount' => count($pageEvents),
            'maxSeenInPage' => $maxSeenInPage,
            'openGaps' => count($openGaps),
            'resolvedGaps' => count($resolvedGapPositions),
            'expiredGaps' => count($expiredGapPositions),
            'newGaps' => count($newGapPositions),
        ]);

        // Combine resolved-gap events with page events, ordered by global_position.
        // Resolved gaps are below the previous checkpoint, so they always come first.
        $deliveryQueue = $resolvedGapEvents + array_combine(
            array_map(fn(StoredEvent $e) => $e->globalPosition, $pageEvents),
            $pageEvents,
        );
        ksort($deliveryQueue);

        $this->storeLog($log);

        if ($processBatches) {
            $this->messageConsumer->beforeHandlingBatch();
        }

        $messageCount = 0;
        $lastCommit = $checkpoint->position;
        $eventsSinceCommit = 0;
        $queueTimedOut = false;
        $lastDeliveredPosition = null;

        // Map page positions to "checkpoint advance target": the highest page
        // position we've passed through (whether handled or skipped). Resolved
        // gaps don't move the checkpoint forward — they sit below it.
        $pagePositionsInOrder = array_map(fn(StoredEvent $e) => $e->globalPosition, $pageEvents);

        /** @var array<string, true> $pausedStreams */
        $pausedStreams = [];

        foreach ($deliveryQueue as $globalPosition => $storedEvent) {
            if ((time() - $startTime) >= $timeoutInSeconds) {
                $queueTimedOut = true;
                $this->appendToActivity($log, 'queue_timeout', 'queue timeout', [
                    'run_time' => time() - $startTime,
                ]);
                break;
            }

            $isFromPage = isset($pageEventPositions[$globalPosition]);
            $matchesFilter = $this->eventTypeMatches($storedEvent->eventType);

            if ($matchesFilter) {
                if ($this->failureMode === FailureMode::PauseStream && $this->isStreamPaused($storedEvent->streamName, $pausedStreams)) {
                    // skip — stream paused
                } else {
                    $retryResult = EventHandlerWithRetry::handle(
                        $this->messageConsumer,
                        $this->storedMessageToContext($storedEvent),
                    );

                    if ($retryResult !== null) {
                        $this->poisonMessageRecorder?->record(
                            $this->subscriptionId,
                            $storedEvent,
                            $retryResult->exception,
                            $retryResult->retryCount,
                        );

                        $this->appendToActivity($log, 'poison_message', 'event marked as poison', [
                            'global_position' => $storedEvent->globalPosition,
                            'stream_name' => $storedEvent->streamName,
                            'event_type' => $storedEvent->eventType,
                            'failure_mode' => $this->failureMode->value,
                            'error' => $retryResult->exception->getMessage(),
                        ]);

                        match ($this->failureMode) {
                            FailureMode::Halt => throw $retryResult->exception,
                            FailureMode::PauseStream => $pausedStreams[$storedEvent->streamName] = true,
                            FailureMode::SkipMessage => null,
                        };
                    }
                }

                $messageCount++;
            }

            if ($isFromPage) {
                $lastDeliveredPosition = $globalPosition;
            }
            $eventsSinceCommit++;

            if (!$processBatches && $isFromPage && $eventsSinceCommit >= $this->streamOptions->commitBatchSize) {
                $lastCommit = $this->commitProgress(
                    $log,
                    $checkpoint,
                    $lastDeliveredPosition,
                    $newGapPositions,
                    $resolvedGapPositions,
                    $expiredGapPositions,
                    $pagePositionsInOrder,
                    $now,
                    'mid_poll',
                );
                $eventsSinceCommit = 0;
                // After commit, any new gaps and closed gaps below lastCommit are persisted —
                // don't re-write them at end of poll.
                $newGapPositions = array_values(array_filter($newGapPositions, fn(int $p) => $p > $lastCommit));
                $resolvedGapPositions = array_values(array_filter($resolvedGapPositions, fn(int $p) => $p > $lastCommit));
                $expiredGapPositions = array_values(array_filter($expiredGapPositions, fn(int $p) => $p > $lastCommit));
            }
        }

        if ($processBatches) {
            $this->messageConsumer->afterHandlingBatch();
        }

        $finalAdvance = $lastDeliveredPosition ?? $maxSeenInPage;

        $needsFinalCommit = ($finalAdvance !== null && $finalAdvance > $lastCommit)
            || $newGapPositions !== []
            || $resolvedGapPositions !== []
            || $expiredGapPositions !== [];

        if ($needsFinalCommit && !$queueTimedOut) {
            $newCheckpointPosition = $finalAdvance ?? $checkpoint->position;
            $this->commitProgress(
                $log,
                $checkpoint,
                $newCheckpointPosition,
                $newGapPositions,
                $resolvedGapPositions,
                $expiredGapPositions,
                $pagePositionsInOrder,
                $now,
                'end_of_poll',
            );
        }

        $this->storeLog($log);

        return $messageCount;
    }

    /**
     * @param array<SubscriptionActivity> $log
     * @param array<int> $newGapPositions
     * @param array<int> $resolvedGapPositions
     * @param array<int> $expiredGapPositions
     * @param array<int> $pagePositionsInOrder
     */
    private function commitProgress(
        array &$log,
        Checkpoints\Checkpoint $checkpoint,
        int $newCheckpointPosition,
        array $newGapPositions,
        array $resolvedGapPositions,
        array $expiredGapPositions,
        array $pagePositionsInOrder,
        DateTimeImmutable $now,
        string $reason,
    ): int {
        // For mid-poll commits we may only have advanced through part of the
        // page. Keep new gaps that are still below the new checkpoint —
        // higher gaps come on a subsequent commit when we reach them.
        $newGapPositionsToWrite = array_values(array_filter(
            $newGapPositions,
            fn(int $p) => $p < $newCheckpointPosition,
        ));

        $closed = array_merge($resolvedGapPositions, $expiredGapPositions);

        $this->appendToActivity($log, 'store_checkpoint', "store checkpoint ({$reason})", [
            'position' => $newCheckpointPosition,
            'newGaps' => $newGapPositionsToWrite,
            'closedGaps' => $closed,
        ]);

        $this->gapStore->commit(
            subscriptionId: $this->subscriptionId,
            newCheckpointPosition: $newCheckpointPosition,
            newGapPositions: $newGapPositionsToWrite,
            closedGapPositions: $closed,
            now: $now,
        );

        $this->storeLog($log);

        return $newCheckpointPosition;
    }

    public function prepareForReplay(): void
    {
        $log = [];
        $this->appendToActivity($log, 'prepare_replay', 'prepare replay');
        if ($this->messageConsumer instanceof MessageConsumerThatResetsBeforeReplay) {
            $this->messageConsumer->prepareReplay();
        }

        $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint', [
            'position' => $this->streamOptions->startingFromPosition,
            'reason' => 'replay',
        ]);
        $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        $this->checkpointStore->store($checkpoint);
        $this->gapStore->deleteAll($this->subscriptionId);
        $this->storeLog($log);
    }

    private function eventTypeMatches(string $eventType): bool
    {
        if ($this->streamOptions->eventTypes === null) {
            return true;
        }

        return in_array($eventType, $this->streamOptions->eventTypes, true);
    }

    private function storedMessageToContext(StoredEvent $storedEvent): MessageConsumeContext
    {
        $payload = $this->eventSerializer->deserialize(
            new SerializationResult(
                eventType: $storedEvent->eventType,
                payload: $storedEvent->payloadJson,
            ),
        );

        /** @var array<string, mixed> $metaData */
        $metaData = json_decode($storedEvent->metadataJson, true);
        return new MessageConsumeContext(
            eventId: $storedEvent->eventId,
            subscriptionId: $this->subscriptionId,
            streamNameType: $storedEvent->streamNameType,
            streamType: $storedEvent->streamType,
            streamNameAsString: $storedEvent->streamName,
            streamName: $this->streamNameTypeMap->typeToClassName($storedEvent->streamNameType)::fromString($storedEvent->streamName),
            eventClass: get_class($payload),
            eventType: $storedEvent->eventType,
            event: $payload,
            metaData: $metaData,
            streamPosition: $storedEvent->streamPosition,
            globalPosition: $storedEvent->globalPosition,
            occurredAt: $storedEvent->createdAt,
        );
    }

    /**
     * @param array<string, true> $pausedStreams
     */
    private function isStreamPaused(string $streamName, array $pausedStreams): bool
    {
        if (isset($pausedStreams[$streamName])) {
            return true;
        }

        if ($this->poisonMessageStore === null) {
            return false;
        }

        return $this->poisonMessageStore->hasUnresolvedForStream($this->subscriptionId, $streamName);
    }

    /**
     * @param array<SubscriptionActivity> $log
     * @param array<string, mixed> $data
     */
    private function appendToActivity(array &$log, string $type, string $message, array $data = []): void
    {
        $log[] = new SubscriptionActivity(
            streamId: $this->subscriptionId,
            type: $type,
            message: $message,
            occurredAt: new \DateTime('now'),
            data: $data,
        );
    }

    /**
     * @param array<SubscriptionActivity> $log
     */
    private function storeLog(array &$log): void
    {
        $this->activityStreamLogger->log(...$log);
        $log = [];
    }
}
