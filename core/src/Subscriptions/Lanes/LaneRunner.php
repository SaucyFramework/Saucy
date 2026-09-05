<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\PoisonMessages\EventHandlerWithRetry;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\PoisonMessages\RetryPolicy;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\MessageStorage\StoredEvent;

/**
 * Runs one projection lane: reads the all-stream ONCE, in global order, and dispatches each
 * event in memory to every member projector that subscribes to it.
 *
 * Each member keeps its own checkpoint, under its own subscription id and in its own checkpoint
 * store, so there is no data migration and a member can leave the lane (pause, replay, catch-up,
 * sync claim) and be picked up by the legacy per-subscription path at any time.
 *
 * The trade-off is head-of-line blocking: a slow or retrying member holds up every other member
 * in the same lane. Put reactors with expensive or money-moving side effects in their own lane.
 */
final class LaneRunner
{
    /** @var array<string, AllStreamSubscription> */
    private array $members;

    /** @var \Closure(string): void */
    private \Closure $startCatchUp;

    private RetryPolicy $retryPolicy;

    /** @var array<string, int> member id => in-memory position */
    private array $positions = [];

    /** @var array<string, int> member id => last position written to the checkpoint store */
    private array $committed = [];

    /** @var array<string, true> member ids the lane currently dispatches to */
    private array $inLane = [];

    /** @var array<string, true> members halted by a poison message during this process */
    private array $haltedInProcess = [];

    /**
     * Members inside the catch-up window that the last full evaluation excluded ONLY because
     * they were claimed or because somebody held their lease.
     *
     * @var array<string, true>
     */
    private array $eligibleExceptClaimed = [];

    /** @var array<string, true> the claimed set as of the last coordinator read */
    private array $claimed = [];

    private ?int $lastSeenVersion = null;

    /**
     * @param array<string, AllStreamSubscription> $members keyed by subscription id
     * @param callable(string): void $startCatchUp starts a standalone catch-up job for a member
     */
    public function __construct(
        private readonly LaneConfig $config,
        array $members,
        private readonly AllStreamReader $eventReader,
        private readonly EventSerializer $eventSerializer,
        private readonly TypeMap $streamNameTypeMap,
        private readonly RunningProcesses $runningProcesses,
        private readonly PoisonMessageStore $poisonMessageStore,
        private readonly ?PoisonMessageRecorder $poisonMessageRecorder,
        private readonly ActivityStreamLogger $activityStreamLogger,
        private readonly LaneCoordinator $coordinator,
        callable $startCatchUp,
        ?RetryPolicy $retryPolicy = null,
    ) {
        $this->members = $members;
        $this->startCatchUp = \Closure::fromCallable($startCatchUp);
        $this->retryPolicy = $retryPolicy ?? $config->retryPolicy();
    }

    public function laneSubscriptionId(): string
    {
        return 'lane__' . $this->config->name;
    }

    /**
     * Reads and dispatches one page.
     */
    public function poll(int $timeoutInSeconds = 100): PollResult
    {
        $startTime = time();
        $activity = new LaneActivityLog($this->activityStreamLogger, $this->laneSubscriptionId());

        $activity->trail('started_poll', 'started poll', [
            'lane' => $this->config->name,
            'timeout' => $timeoutInSeconds,
            'start_time' => $startTime,
        ]);

        $this->syncWithCoordinator();

        if ($this->inLane === []) {
            $activity->flush(includeTrail: false);
            return new PollResult(0, false);
        }

        $fromPosition = min(array_intersect_key($this->positions, $this->inLane));
        $eventTypes = $this->eventTypeUnion();

        // Read the head BEFORE the page, exactly as AllStreamSubscription::poll() does. Reading
        // it afterwards would let an event committed between the (empty) page and the read be
        // skipped forever by the idle advance below.
        $headBeforePage = $this->eventReader->maxEventId();

        $activity->trail('loading_events', 'loading events', [
            'lane' => $this->config->name,
            'fromPosition' => $fromPosition,
            'limit' => $this->config->pageSize,
            'members' => count($this->inLane),
        ]);

        $storedEvents = $this->eventReader->paginate(
            new AllStreamQuery(
                fromPosition: $fromPosition,
                limit: $this->config->pageSize,
                eventTypes: $eventTypes,
            ),
        );

        $activity->trail('loaded_events', 'loaded events', [
            'lane' => $this->config->name,
            'run_time' => time() - $startTime,
        ]);

        $eventsRead = 0;
        $queueTimedOut = false;
        $batchOpened = [];
        /** @var array<string, int> $processed */
        $processed = [];
        /**
         * Streams a PauseStream member is skipping. Local to this poll and rebuilt from
         * PoisonMessageStore::hasUnresolvedForStream(), exactly like AllStreamSubscription:
         * caching it across polls would keep skipping a stream an operator has since resolved,
         * while the checkpoint kept advancing past those events.
         *
         * @var array<string, array<string, true>> member id => stream name => true
         */
        $pausedStreams = [];
        $commitBatchSize = $this->config->effectiveCommitBatchSize();

        foreach ($storedEvents as $storedEvent) {
            if ((time() - $startTime) >= $timeoutInSeconds) {
                $queueTimedOut = true;
                $activity->trail('queue_timeout', 'queue timeout', [
                    'lane' => $this->config->name,
                    'timeout' => $timeoutInSeconds,
                    'run_time' => time() - $startTime,
                ]);
                break;
            }

            if ($eventsRead === 0) {
                $batchOpened = $this->openBatches();
            }

            $eventsRead++;
            $this->dispatch($storedEvent, $activity, $processed, $pausedStreams);

            if ($eventsRead % $commitBatchSize === 0) {
                // Batch consumers are excluded: their work is only flushed by afterHandlingBatch().
                $this->commit($activity, $processed, $startTime, skipBatchMembers: true);
                $activity->flush(includeTrail: true);
                $processed = [];
            }
        }

        $this->closeBatches($batchOpened);

        if ($eventsRead === 0 && !$queueTimedOut) {
            // Nothing to see: every in-lane member is up to date with the head as it stood when
            // the page was read. A member pinned above it never moves backwards.
            foreach (array_keys($this->inLane) as $memberId) {
                $this->positions[$memberId] = max($this->positions[$memberId], $headBeforePage);
            }
        }

        $this->commit($activity, $processed, $startTime, skipBatchMembers: false);
        $activity->flush(includeTrail: $eventsRead > 0 || $queueTimedOut);

        return new PollResult($eventsRead, $queueTimedOut);
    }

    /**
     * @param array<string, int> $processed
     * @param array<string, array<string, true>> $pausedStreams
     */
    private function dispatch(
        StoredEvent $storedEvent,
        LaneActivityLog $activity,
        array &$processed,
        array &$pausedStreams,
    ): void {
        /** @var array{object, array<string, mixed>, \Saucy\Core\Events\Streams\StreamName}|null $decoded */
        $decoded = null;
        $decodeFailure = null;

        foreach (array_keys($this->inLane) as $memberId) {
            if ($this->positions[$memberId] >= $storedEvent->globalPosition) {
                continue;
            }

            $member = $this->members[$memberId];

            // The lane read covers every type any member wants, so a member that does not
            // subscribe to this type has nothing in between to see: advance it.
            if (!$this->subscribesTo($member, $storedEvent->eventType)) {
                $this->positions[$memberId] = $storedEvent->globalPosition;
                continue;
            }

            if ($member->failureMode === FailureMode::PauseStream
                && $this->isStreamPaused($memberId, $storedEvent->streamName, $pausedStreams)) {
                $this->positions[$memberId] = $storedEvent->globalPosition;
                continue;
            }

            if ($decoded === null && $decodeFailure === null) {
                try {
                    // Deserialise once per event, not once per member.
                    $decoded = $this->decode($storedEvent);
                } catch (\Throwable $e) {
                    // An unmapped event type or stream-name type would otherwise throw out of
                    // poll() and crash-loop the WHOLE lane. Treat it as a poison message for
                    // every member that wanted this event, and keep the lane going.
                    $decodeFailure = $e;
                }
            }

            if ($decodeFailure !== null) {
                $this->poison($memberId, $storedEvent, $decodeFailure, 0, $activity, $pausedStreams);
                continue;
            }

            /** @var array{object, array<string, mixed>, \Saucy\Core\Events\Streams\StreamName} $decoded */
            [$payload, $metaData, $streamName] = $decoded;

            $retryResult = EventHandlerWithRetry::handle(
                $member->messageConsumer,
                new MessageConsumeContext(
                    eventId: $storedEvent->eventId,
                    subscriptionId: $memberId,
                    streamNameType: $storedEvent->streamNameType,
                    streamType: $storedEvent->streamType,
                    streamNameAsString: $storedEvent->streamName,
                    streamName: $streamName,
                    eventClass: get_class($payload),
                    eventType: $storedEvent->eventType,
                    event: $payload,
                    metaData: $metaData,
                    streamPosition: $storedEvent->streamPosition,
                    globalPosition: $storedEvent->globalPosition,
                    occurredAt: $storedEvent->createdAt,
                ),
                $this->retryPolicy,
            );

            if ($retryResult !== null) {
                $this->poison(
                    $memberId,
                    $storedEvent,
                    $retryResult->exception,
                    $retryResult->retryCount,
                    $activity,
                    $pausedStreams,
                );
                continue;
            }

            $this->positions[$memberId] = $storedEvent->globalPosition;
            $processed[$memberId] = ($processed[$memberId] ?? 0) + 1;
        }
    }

    /**
     * @return array{object, array<string, mixed>, \Saucy\Core\Events\Streams\StreamName}
     */
    private function decode(StoredEvent $storedEvent): array
    {
        $payload = $this->eventSerializer->deserialize(
            new SerializationResult(
                eventType: $storedEvent->eventType,
                payload: $storedEvent->payloadJson,
            ),
        );

        /** @var array<string, mixed> $metaData */
        $metaData = json_decode($storedEvent->metadataJson, true) ?? [];

        $streamName = $this->streamNameTypeMap
            ->typeToClassName($storedEvent->streamNameType)::fromString($storedEvent->streamName);

        return [$payload, $metaData, $streamName];
    }

    /**
     * Records a poison message under the MEMBER's id and applies the MEMBER's failure mode.
     *
     * @param array<string, array<string, true>> $pausedStreams
     */
    private function poison(
        string $memberId,
        StoredEvent $storedEvent,
        \Throwable $exception,
        int $retryCount,
        LaneActivityLog $activity,
        array &$pausedStreams,
    ): void {
        $member = $this->members[$memberId];

        $this->poisonMessageRecorder?->record($memberId, $storedEvent, $exception, $retryCount);

        $activity->recordForMember($memberId, 'poison_message', 'event marked as poison', [
            'lane' => $this->config->name,
            'global_position' => $storedEvent->globalPosition,
            'stream_name' => $storedEvent->streamName,
            'event_type' => $storedEvent->eventType,
            'failure_mode' => $member->failureMode->value,
            'error' => $exception->getMessage(),
        ]);

        match ($member->failureMode) {
            // The member leaves the lane; its in-memory position is deliberately kept at the last
            // event it handled successfully, so the end-of-page commit persists that progress and
            // the poison event is re-delivered once it is resolved. Other members keep going.
            FailureMode::Halt => $this->haltMember($memberId),
            FailureMode::PauseStream => $this->pauseStreamForMember($memberId, $storedEvent, $pausedStreams),
            FailureMode::SkipMessage => $this->positions[$memberId] = $storedEvent->globalPosition,
        };
    }

    private function haltMember(string $memberId): void
    {
        unset($this->inLane[$memberId]);
        $this->haltedInProcess[$memberId] = true;
    }

    /**
     * @param array<string, array<string, true>> $pausedStreams
     */
    private function pauseStreamForMember(string $memberId, StoredEvent $storedEvent, array &$pausedStreams): void
    {
        $pausedStreams[$memberId][$storedEvent->streamName] = true;
        $this->positions[$memberId] = $storedEvent->globalPosition;
    }

    /**
     * @return array<string, true> members whose beforeHandlingBatch() was called
     */
    private function openBatches(): array
    {
        $opened = [];
        foreach (array_keys($this->inLane) as $memberId) {
            $consumer = $this->members[$memberId]->messageConsumer;
            if ($consumer instanceof MessageConsumerThatHandlesBatches) {
                $consumer->beforeHandlingBatch();
                $opened[$memberId] = true;
            }
        }

        return $opened;
    }

    /**
     * @param array<string, true> $opened
     */
    private function closeBatches(array $opened): void
    {
        foreach (array_keys($opened) as $memberId) {
            $consumer = $this->members[$memberId]->messageConsumer;
            if ($consumer instanceof MessageConsumerThatHandlesBatches) {
                $consumer->afterHandlingBatch();
            }
        }
    }

    /**
     * Writes each member's checkpoint, but only when it moved.
     *
     * @param array<string, int> $processed
     */
    private function commit(LaneActivityLog $activity, array $processed, int $startTime, bool $skipBatchMembers): void
    {
        $advanced = 0;
        foreach ($this->positions as $memberId => $position) {
            if (($this->committed[$memberId] ?? null) === $position) {
                continue;
            }

            if (!isset($this->members[$memberId])) {
                continue;
            }

            if ($skipBatchMembers && $this->members[$memberId]->messageConsumer instanceof MessageConsumerThatHandlesBatches) {
                continue;
            }

            $this->members[$memberId]->checkpointStore->store(new Checkpoint($memberId, $position));
            $this->committed[$memberId] = $position;
            $advanced++;
        }

        // The checkpoint writes above always happen; the activity ROW is only worth writing when
        // a member actually handled something. An idle lane still advances every member past
        // event types nobody in the lane subscribes to, which at a 250 ms sleep would be four
        // store_checkpoint rows a second per lane, forever.
        if ($advanced === 0 || $processed === []) {
            return;
        }

        $activity->record('store_checkpoint', 'store checkpoint', [
            'lane' => $this->config->name,
            'members_advanced' => $advanced,
            'messages_processed' => $processed,
            'run_time' => time() - $startTime,
        ]);
    }

    /**
     * Reads the coordinator row ONCE. A structural bump (pause/resume/replay, poison retry/skip,
     * a finished catch-up job) triggers a full membership evaluation; a claim bump only re-syncs
     * the sync-claimed set.
     */
    private function syncWithCoordinator(): void
    {
        $lane = $this->config->name;
        $state = $this->coordinator->read($lane);

        if ($this->lastSeenVersion !== null && $state->membershipVersion === $this->lastSeenVersion) {
            return;
        }

        if ($this->lastSeenVersion === null || $state->structuralPending) {
            $this->evaluateMembership($state);
        } else {
            $this->resyncClaims($state);
        }

        $this->lastSeenVersion = $state->membershipVersion;
        $this->coordinator->acknowledge($lane, $state->membershipVersion);
    }

    private function evaluateMembership(LaneCoordinationState $state): void
    {
        $lane = $this->config->name;
        $this->claimed = array_fill_keys($state->claimedMembers, true);

        // A poison message that is resolved externally must be able to bring the member back,
        // so the sticky in-process halt is dropped here and getUnresolved() decides again.
        $this->haltedInProcess = [];

        $membership = LaneMembership::evaluate(
            members: $this->members,
            claimed: $this->claimed,
            laneHead: $this->eventReader->maxEventId(),
            catchUpThreshold: $this->config->catchUpThreshold,
            runningProcesses: $this->runningProcesses,
            poisonMessageStore: $this->poisonMessageStore,
        );

        $this->eligibleExceptClaimed = $membership->eligibleExceptClaimed;
        $this->inLane = array_fill_keys($membership->inLane, true);

        foreach (array_keys($this->members) as $memberId) {
            if (isset($this->inLane[$memberId])) {
                $this->positions[$memberId] = $membership->positions[$memberId];
                $this->committed[$memberId] = $membership->positions[$memberId];
                continue;
            }

            // Anything out of the lane loses its tracked state, so a later re-inclusion always
            // re-reads the checkpoint (a replay reset must not be masked by a stale position).
            $this->forgetMemberState($memberId);
        }

        // Claims whose claimer died before its finally block ran.
        foreach ($membership->staleClaims as $memberId) {
            unset($this->claimed[$memberId]);
            $this->coordinator->release($lane, $memberId);
        }

        foreach ($membership->catchUp as $memberId) {
            ($this->startCatchUp)($memberId);
        }
    }

    /**
     * Claim bump: only the sync-claimed set changed. Drop newly claimed members and re-add the
     * ones whose only exclusion reason was a claim or a lease.
     */
    private function resyncClaims(LaneCoordinationState $state): void
    {
        $claimed = array_fill_keys($state->claimedMembers, true);

        foreach (array_keys($claimed) as $memberId) {
            if (isset($this->inLane[$memberId])) {
                unset($this->inLane[$memberId]);
                $this->eligibleExceptClaimed[$memberId] = true;
                $this->forgetMemberState($memberId);
            }
        }

        foreach (array_keys($this->eligibleExceptClaimed) as $memberId) {
            if (isset($claimed[$memberId])) {
                continue;
            }

            if (!isset($this->members[$memberId]) || isset($this->haltedInProcess[$memberId])) {
                continue;
            }

            // Leaving the claimed set is NOT proof that the member is free. The claimed set is
            // a plain set and release() is an unset, so an inline run that finishes late can
            // delete a newer run's claim for the same member; re-admitting on that alone would
            // put the lane and the newer run on the same events. The member's own lease is the
            // authority, so it is always read before a re-admission. isActive() reports a paused
            // member as inactive, hence the explicit isPaused() check alongside it.
            if ($this->runningProcesses->isPaused($memberId) || $this->runningProcesses->isActive($memberId)) {
                continue;
            }

            unset($this->eligibleExceptClaimed[$memberId]);

            // A re-added member's position is re-read from its checkpoint store.
            $this->positions[$memberId] = $this->readPosition($memberId);
            $this->committed[$memberId] = $this->positions[$memberId];
            $this->inLane[$memberId] = true;
        }

        $this->claimed = $claimed;
    }

    private function readPosition(string $memberId): int
    {
        $member = $this->members[$memberId];

        try {
            return $member->checkpointStore->get($memberId)->position;
        } catch (CheckpointNotFound) {
            return $member->streamOptions->startingFromPosition;
        }
    }

    private function forgetMemberState(string $memberId): void
    {
        unset($this->positions[$memberId], $this->committed[$memberId]);
    }

    private function subscribesTo(AllStreamSubscription $member, string $eventType): bool
    {
        $types = $member->streamOptions->eventTypes;

        return $types === null || in_array($eventType, $types, true);
    }

    /**
     * @param array<string, array<string, true>> $pausedStreams
     */
    private function isStreamPaused(string $memberId, string $streamName, array $pausedStreams): bool
    {
        if (isset($pausedStreams[$memberId][$streamName])) {
            return true;
        }

        return $this->poisonMessageStore->hasUnresolvedForStream($memberId, $streamName);
    }

    /**
     * @return array<string>|null null when a member subscribes to every type
     */
    private function eventTypeUnion(): ?array
    {
        $types = [];
        foreach (array_keys($this->inLane) as $memberId) {
            $memberTypes = $this->members[$memberId]->streamOptions->eventTypes;
            if ($memberTypes === null) {
                return null;
            }
            foreach ($memberTypes as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }
}
