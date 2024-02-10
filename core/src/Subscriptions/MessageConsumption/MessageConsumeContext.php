<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

use Saucy\Core\Events\Streams\StreamName;

readonly class MessageConsumeContext
{
    /**
     * @param array<string, mixed> $metaData
     */
    public function __construct(
        public string $eventId,
        public string $subscriptionId,
        public string $streamNameType,
        public string $streamType,
        public string $streamNameAsString,
        public StreamName $streamName,
        public string $eventClass,
        public string $eventType,
        public object $event,
        public array $metaData,
        public int $streamPosition,
        public int $globalPosition,
    ) {}
}
