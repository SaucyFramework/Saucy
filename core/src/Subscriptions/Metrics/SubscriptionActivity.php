<?php

namespace Saucy\Core\Subscriptions\Metrics;

final readonly class SubscriptionActivity
{
    public function __construct(
        public string $streamId,
        public string $type,
        public string $message,
        public \DateTime $occurredAt,
        public array $data = [],
    ) {}
}
