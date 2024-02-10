<?php

namespace Saucy\Core\Events\Streams;

final readonly class StreamEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $eventId,
        public object $payload,
        public array $metadata,
        public int $position,
    ) {}
}
