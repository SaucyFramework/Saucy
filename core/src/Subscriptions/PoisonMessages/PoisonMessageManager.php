<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Support\Collection;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscription;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\MessageStorage\StoredEvent;

final readonly class PoisonMessageManager
{
    public function __construct(
        private PoisonMessageStore $poisonMessageStore,
        private AllStreamSubscriptionRegistry $allStreamRegistry,
        private StreamSubscriptionRegistry $streamRegistry,
        private SyncStreamSubscriptionRegistry $syncStreamRegistry,
        private AllStreamSubscriptionProcessManager $allStreamProcessManager,
        private StreamSubscriptionProcessManager $streamProcessManager,
        private ReadEventData $readEventData,
        private EventSerializer $eventSerializer,
        private TypeMap $typeMap,
    ) {}

    /**
     * @return Collection<int, PoisonMessage>
     */
    public function listUnresolved(?string $subscriptionId = null): Collection
    {
        return new Collection($this->poisonMessageStore->getUnresolved($subscriptionId));
    }

    public function retry(int $poisonMessageId): void
    {
        $poisonMessage = $this->poisonMessageStore->get($poisonMessageId);
        $consumer = $this->resolveConsumer($poisonMessage->subscriptionId);

        $storedEvent = $this->readEventData->getForEventId($poisonMessage->messageId);

        $context = $this->storedEventToContext($storedEvent, $poisonMessage->subscriptionId);

        try {
            $consumer->handle($context);
            $this->poisonMessageStore->resolve($poisonMessageId);
        } catch (\Throwable $e) {
            $this->poisonMessageStore->updateAfterFailedRetry(
                $poisonMessageId,
                $e->getMessage(),
                $e->getTraceAsString(),
            );
            throw $e;
        }

        $this->handlePostResolution($poisonMessage, $storedEvent);
    }

    public function skip(int $poisonMessageId): void
    {
        $poisonMessage = $this->poisonMessageStore->get($poisonMessageId);
        $storedEvent = $this->readEventData->getForEventId($poisonMessage->messageId);

        $this->poisonMessageStore->skip($poisonMessageId);

        $this->handlePostResolution($poisonMessage, $storedEvent);
    }

    private function handlePostResolution(PoisonMessage $poisonMessage, StoredEvent $storedEvent): void
    {
        if (isset($this->allStreamRegistry->streams[$poisonMessage->subscriptionId])) {
            $subscription = $this->allStreamRegistry->streams[$poisonMessage->subscriptionId];
            $this->handleAllStreamPostResolution($subscription, $poisonMessage, $storedEvent);
            $this->allStreamProcessManager->startProcess($poisonMessage->subscriptionId);
            return;
        }

        if (isset($this->streamRegistry->streams[$poisonMessage->subscriptionId])) {
            $subscription = $this->streamRegistry->streams[$poisonMessage->subscriptionId];
            $this->handleStreamSubscriptionPostResolution($subscription, $storedEvent);
            $streamName = $this->resolveStreamName($storedEvent);
            if ($streamName instanceof AggregateStreamName) {
                $this->streamProcessManager->startProcessesForAggregateInstance($streamName);
            }
            return;
        }

        if (isset($this->syncStreamRegistry->streams[$poisonMessage->subscriptionId])) {
            $subscription = $this->syncStreamRegistry->streams[$poisonMessage->subscriptionId];
            $this->handleStreamSubscriptionPostResolution($subscription, $storedEvent);
        }
    }

    private function handleAllStreamPostResolution(
        AllStreamSubscription $subscription,
        PoisonMessage $poisonMessage,
        StoredEvent $storedEvent,
    ): void {
        try {
            $checkpoint = $subscription->checkpointStore->get($subscription->subscriptionId);
        } catch (CheckpointNotFound) {
            $checkpoint = new Checkpoint($subscription->subscriptionId, $subscription->streamOptions->startingFromPosition);
        }

        match ($subscription->failureMode) {
            FailureMode::Halt => $this->advanceCheckpointIfBehind(
                $subscription->checkpointStore,
                $checkpoint,
                $storedEvent->globalPosition,
            ),
            FailureMode::PauseStream => $this->replaySkippedAllStreamEvents(
                $subscription,
                $poisonMessage,
                $checkpoint,
            ),
            FailureMode::SkipMessage => null,
        };
    }

    private function handleStreamSubscriptionPostResolution(
        StreamSubscription $subscription,
        StoredEvent $storedEvent,
    ): void {
        $streamName = $this->resolveStreamName($storedEvent);
        $streamIdentifier = $subscription->getId($streamName);

        try {
            $checkpoint = $subscription->checkpointStore->get($streamIdentifier);
        } catch (CheckpointNotFound) {
            $checkpoint = new Checkpoint($streamIdentifier, $subscription->streamOptions->startingFromPosition);
        }

        // StreamSubscription treats PauseStream as Halt
        $effectiveMode = $subscription->failureMode === FailureMode::PauseStream
            ? FailureMode::Halt
            : $subscription->failureMode;

        if ($effectiveMode === FailureMode::Halt) {
            $this->advanceCheckpointIfBehind(
                $subscription->checkpointStore,
                $checkpoint,
                $storedEvent->streamPosition,
            );
        }
    }

    private function advanceCheckpointIfBehind(
        \Saucy\Core\Subscriptions\Checkpoints\CheckpointStore $checkpointStore,
        Checkpoint $checkpoint,
        int $targetPosition,
    ): void {
        if ($checkpoint->position < $targetPosition) {
            $checkpointStore->store($checkpoint->withPosition($targetPosition));
        }
    }

    /**
     * In PauseStream mode, events from the poisoned stream were skipped while the checkpoint advanced.
     * After resolution, replay those skipped events if no unresolved poison messages remain for the stream.
     */
    private function replaySkippedAllStreamEvents(
        AllStreamSubscription $subscription,
        PoisonMessage $poisonMessage,
        Checkpoint $checkpoint,
    ): void {
        // Only replay when ALL poison messages for this stream are resolved
        if ($this->poisonMessageStore->hasUnresolvedForStream($poisonMessage->subscriptionId, $poisonMessage->streamName)) {
            return;
        }

        // Nothing to replay if checkpoint is at or before the poison message
        if ($checkpoint->position <= $poisonMessage->globalPosition) {
            return;
        }

        // Query events from after the poison message up to the checkpoint position
        // fromPosition is exclusive (>), so this starts after the poison message
        $storedEvents = $subscription->eventReader->paginate(
            new AllStreamQuery(
                fromPosition: $poisonMessage->globalPosition,
                limit: $checkpoint->position - $poisonMessage->globalPosition,
            ),
        );

        $allowedEventTypes = $subscription->streamOptions->eventTypes;
        foreach ($storedEvents as $event) {
            if ($event->globalPosition > $checkpoint->position) {
                break;
            }

            // Only replay events from the paused stream that match the
            // subscription's event-type filter (paginate returns the
            // unfiltered global stream — gap detection needs that).
            if ($event->streamName !== $poisonMessage->streamName) {
                continue;
            }

            if ($allowedEventTypes !== null && !in_array($event->eventType, $allowedEventTypes, true)) {
                continue;
            }

            $subscription->messageConsumer->handle(
                $this->storedEventToContext($event, $poisonMessage->subscriptionId),
            );
        }
    }

    private function storedEventToContext(StoredEvent $storedEvent, string $subscriptionId): MessageConsumeContext
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
            subscriptionId: $subscriptionId,
            streamNameType: $storedEvent->streamNameType,
            streamType: $storedEvent->streamType,
            streamNameAsString: $storedEvent->streamName,
            streamName: $this->resolveStreamName($storedEvent),
            eventClass: get_class($payload),
            eventType: $storedEvent->eventType,
            event: $payload,
            metaData: $metaData,
            streamPosition: $storedEvent->streamPosition,
            globalPosition: $storedEvent->globalPosition,
            occurredAt: $storedEvent->createdAt,
        );
    }

    private function resolveStreamName(StoredEvent $storedEvent): StreamName
    {
        return $this->typeMap->typeToClassName($storedEvent->streamNameType)::fromString($storedEvent->streamName);
    }

    private function resolveConsumer(string $subscriptionId): MessageConsumer
    {
        // Try all-stream subscriptions first
        if (isset($this->allStreamRegistry->streams[$subscriptionId])) {
            return $this->allStreamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        // Try async stream subscriptions
        if (isset($this->streamRegistry->streams[$subscriptionId])) {
            return $this->streamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        // Try sync stream subscriptions
        if (isset($this->syncStreamRegistry->streams[$subscriptionId])) {
            return $this->syncStreamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        throw new \RuntimeException("No subscription found for ID: {$subscriptionId}");
    }
}
