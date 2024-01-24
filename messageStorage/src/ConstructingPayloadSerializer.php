<?php

namespace Saucy\MessageStorage;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Serialisation\TypeMap;

final readonly class ConstructingPayloadSerializer implements EventSerializer
{
    public function __construct(
        private TypeMap $typeMap,
    )
    {
    }

    public function serialize(StreamEvent $event): StoredEvent
    {
        $payload = $event->payload;
        if(!$payload instanceof SerializablePayload){
            throw new \Exception('Event must be serializable');
        }

        return new StoredEvent(
            eventId: $event->eventId,
            eventType: $this->typeMap->instanceToType($payload),
            payloadJson: json_encode($payload->toPayload()),
            metadataJson: json_encode($event->metadata),
            position: $event->position,
        );
    }

    public function deserialize(StoredEvent $storedEvent): StreamEvent
    {
        $className = $this->typeMap->typeToClassName($storedEvent->eventType);
        return new StreamEvent(
            eventId: $storedEvent->eventId,
            payload: $className::fromPayload(json_decode($storedEvent->payloadJson, true)),
            metadata: json_decode($storedEvent->metadataJson, true),
            position: $storedEvent->position,
        );
    }
}
