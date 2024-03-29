<?php

namespace Saucy\Core\Subscriptions\AllStream;

use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\Checkpoints;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\ConsumePipe;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
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
        public ConsumePipe $consumePipe,
        public AllStreamReader $eventReader,
        public EventSerializer $eventSerializer,
        public CheckpointStore $checkpointStore,
        public TypeMap $streamNameTypeMap,
    ) {}

    public function poll(): int
    {
        try {
            $checkpoint = $this->checkpointStore->get($this->subscriptionId);
        } catch (Checkpoints\CheckpointNotFound $e) {
            $checkpoint = new Checkpoints\Checkpoint($this->subscriptionId, $this->streamOptions->startingFromPosition);
        }

        $storedEvents = $this->eventReader->paginate(
            new AllStreamQuery(
                fromPosition: $checkpoint->position,
                limit: $this->streamOptions->pageSize,
                eventTypes: $this->streamOptions->eventTypes
            )
        );

        $messageCount = 0;
        $lastCommit = 0;

        foreach ($storedEvents as $storedEvent) {
            $this->consumePipe->handle($this->storedMessageToContext($storedEvent));
            $messageCount += 1;
            // if batch size reached, commit
            if($messageCount === $this->streamOptions->commitBatchSize) {
                $this->checkpointStore->store($checkpoint->withPosition($storedEvent->globalPosition));
                $lastCommit = $storedEvent->globalPosition;
            }
        }

        if(isset($storedEvent) && $lastCommit !== $storedEvent->globalPosition) {
            $this->checkpointStore->store($checkpoint->withPosition($storedEvent->globalPosition));
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
        );
    }
}
