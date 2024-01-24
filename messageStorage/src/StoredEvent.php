<?php

namespace Saucy\MessageStorage;

final readonly class StoredEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $payloadJson,
        public string $metadataJson,
        public int $position,
    ) {
    }
}
