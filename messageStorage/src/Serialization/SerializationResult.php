<?php

namespace Saucy\MessageStorage\Serialization;

final readonly class SerializationResult
{
    public function __construct(
        public string $eventType,
        public string $payload,
    )
    {
    }
}
