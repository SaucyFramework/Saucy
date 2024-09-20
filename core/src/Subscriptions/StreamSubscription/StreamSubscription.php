<?php

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
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
    ) {}

    public function poll(StreamName $streamName): int
    {
        $streamIdentifier = $this->getId($streamName);
        try {
            $checkpoint = $this->checkpointStore->get($streamIdentifier);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($streamIdentifier, $this->streamOptions->startingFromPosition);
        }

        $maxPosition = $this->eventReader->maxStreamPosition($streamName);

        $storedEvents = $this->eventReader->retrieveAllInStreamSinceCheckpoint($streamName, $checkpoint->position);

        $messageCount = 0;
        $lastCommit = 0;

        foreach ($storedEvents as $storedEvent) {
            $this->consumePipe->handle($this->storedMessageToContext($storedEvent));
            $messageCount += 1;
            // if batch size reached, commit
            if($messageCount === $this->streamOptions->commitBatchSize) {
                $this->checkpointStore->store($checkpoint->withPosition($storedEvent->streamPosition));
                $lastCommit = $storedEvent->streamPosition;
            }
        }

        if(isset($storedEvent) && $lastCommit !== $storedEvent->streamPosition) {
            $this->checkpointStore->store($checkpoint->withPosition($storedEvent->streamPosition));
        }

        if($messageCount === 0) {
            $this->checkpointStore->store($checkpoint->withPosition($maxPosition));
        }

        return $messageCount;
    }

    private function storedMessageToContext(StoredEvent $storedEvent): MessageConsumeContext
    {
        $payload = $this->eventSerializer->deserialize(
            new SerializationResult(
                eventType: $storedEvent->eventType,
                payload: $storedEvent->payloadJson,
            )
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

    public function getId(StreamName $streamName): string
    {
        return $this->subscriptionId . '_' . $streamName->toString();
    }
}
