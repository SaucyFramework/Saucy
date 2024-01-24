<?php

namespace Saucy\Core\Events\Streams;

final readonly class StreamEvent
{
    public function __construct(
        public string $eventId,
        public object $payload,
        public array $metadata,
        public int $position,
    )
    {
    }
}
