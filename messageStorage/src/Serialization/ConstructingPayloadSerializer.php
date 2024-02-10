<?php

namespace Saucy\MessageStorage\Serialization;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Serialisation\TypeMap;

final readonly class ConstructingPayloadSerializer implements EventSerializer
{
    public function __construct(
        private TypeMap $typeMap,
    ) {}
    public function serialize(object $event): SerializationResult
    {
        if(!$event instanceof SerializablePayload) {
            throw new \Exception('Event must be serializable');
        }

        return new SerializationResult(
            eventType: $this->typeMap->instanceToType($event),
            payload: json_encode($event->toPayload(), JSON_THROW_ON_ERROR),
        );

    }

    public function deserialize(SerializationResult $serializationResult): object
    {
        return $this->typeMap->typeToClassName($serializationResult->eventType)::fromPayload(json_decode($serializationResult->payload, true));
    }
}
