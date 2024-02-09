<?php

namespace Saucy\MessageStorage;

final readonly class StoredEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $streamNameType,
        public string $streamType,
        public string $streamName,
        public string $payloadJson,
        public string $metadataJson,
        public int $streamPosition,
        public int $globalPosition,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
