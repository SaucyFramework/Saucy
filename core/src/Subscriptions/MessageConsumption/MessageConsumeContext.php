<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

readonly class MessageConsumeContext
{
    public function __construct(
        public string $eventId,
        public string $subscriptionId,
        public string $streamName,
        public string $eventClass,
        public string $eventType,
        public object $event,
        public array $metaData,
        public int $streamPosition,
        public int $globalPosition,
    )
    {
    }
}
