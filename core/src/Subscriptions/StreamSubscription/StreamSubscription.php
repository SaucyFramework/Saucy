<?php

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatResetsStreamBeforeReplay;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\SubscriptionActivity;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\StreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\MessageStorage\StoredEvent;

final readonly class StreamSubscription
{
    public function __construct(
        public string $subscriptionId,
        // for now this is placed here, but can imagine this moving to an "AggregateSubscription child of the StreamSubscription class, since it's not used here
        public string $aggregateType,
        public StreamOptions $streamOptions,
        public MessageConsumer $messageConsumer,
        public StreamReader $eventReader,
        public EventSerializer $eventSerializer,
        public CheckpointStore $checkpointStore,
        public TypeMap $streamNameTypeMap,
        public FailureMode $failureMode = FailureMode::Halt,
        public ?PoisonMessageRecorder $poisonMessageRecorder = null,
        public ?ActivityStreamLogger $activityStreamLogger = null,
    ) {}

    public function poll(StreamName $streamName): int
    {
        $log = [];
        $streamIdentifier = $this->getId($streamName);

        $this->appendToActivity($log, 'started_poll', 'started poll', [
            'stream_name' => $streamName->toString(),
        ]);

        try {
            $checkpoint = $this->checkpointStore->get($streamIdentifier);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($streamIdentifier, $this->streamOptions->startingFromPosition);
            $this->checkpointStore->store($checkpoint);
        }

        $maxPosition = $this->eventReader->maxStreamPosition($streamName);

        $storedEvents = $this->eventReader->retrieveAllInStreamSinceCheckpoint($streamName, $checkpoint->position);

        $messageCount = 0;
        $lastCommit = 0;

        // For StreamSubscription, PauseStream behaves as Halt (single stream)
        $effectiveFailureMode = $this->failureMode === FailureMode::PauseStream
            ? FailureMode::Halt
            : $this->failureMode;

        $this->storeLog($log);

        foreach ($storedEvents as $storedEvent) {
            try {
                $this->messageConsumer->handle($this->storedMessageToContext($storedEvent));
            } catch (\Throwable $e) {
                $this->poisonMessageRecorder?->record(
                    $this->subscriptionId,
                    $storedEvent,
                    $e,
                    0,
                );

                $this->appendToActivity($log, 'poison_message', 'event marked as poison', [
                    'stream_position' => $storedEvent->streamPosition,
                    'stream_name' => $storedEvent->streamName,
                    'event_type' => $storedEvent->eventType,
                    'failure_mode' => $this->failureMode->value,
                    'error' => $e->getMessage(),
                ]);

                $this->storeLog($log);

                match ($effectiveFailureMode) {
                    FailureMode::Halt, FailureMode::PauseStream => throw $e,
                    FailureMode::SkipMessage => null,
                };

                $messageCount += 1;
                continue;
            }

            $messageCount += 1;
            // if batch size reached, commit
            if ($messageCount === $this->streamOptions->commitBatchSize) {
                $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint', [
                    'position' => $storedEvent->streamPosition,
                ]);
                $this->checkpointStore->store($checkpoint->withPosition($storedEvent->streamPosition));
                $this->storeLog($log);
                $lastCommit = $storedEvent->streamPosition;
            }
        }

        if (isset($storedEvent) && $lastCommit !== $storedEvent->streamPosition) {
            $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint, end of loop', [
                'position' => $storedEvent->streamPosition,
            ]);
            $this->checkpointStore->store($checkpoint->withPosition($storedEvent->streamPosition));
        }

        if ($messageCount === 0) {
            $this->appendToActivity($log, 'store_checkpoint', 'store checkpoint, 0 handled', [
                'position' => $maxPosition,
            ]);
            $this->checkpointStore->store($checkpoint->withPosition($maxPosition));
        }

        $this->storeLog($log);
        return $messageCount;
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

    public function prepareForReplay(AggregateStreamName $streamName): void
    {
        if ($this->messageConsumer instanceof MessageConsumerThatResetsStreamBeforeReplay) {
            $this->messageConsumer->prepareStreamReplay($streamName);
        }

        $checkpoint = new Checkpoints\Checkpoint(
            $this->getId($streamName),
            $this->streamOptions->startingFromPosition,
        );
        $this->checkpointStore->store($checkpoint);
    }

    public function getId(StreamName $streamName): string
    {
        return $this->subscriptionId . '_' . $streamName->toString();
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
        if ($log !== [] && $this->activityStreamLogger !== null) {
            $this->activityStreamLogger->log(...$log);
        }
        $log = [];
    }
}
