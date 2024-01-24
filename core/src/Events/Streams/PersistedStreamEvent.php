<?php

namespace Saucy\Core\Events\Streams;

final readonly class PersistedStreamEvent
{
    public function __construct(
        public string $eventId,
        public object $payload,
        public array $metadata,
        public int $position,
        public int $globalPosition,
    )
    {
    }
}
