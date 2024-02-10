<?php

namespace Saucy\MessageStorage\Serialization;

interface EventSerializer
{
    public function serialize(object $event): SerializationResult;

    public function deserialize(SerializationResult $serializationResult): object;
}
