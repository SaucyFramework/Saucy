<?php

namespace Saucy\Core\Subscriptions\Metrics;

final readonly class NoOpLogger implements ActivityStreamLogger
{
    public function log(SubscriptionActivity ...$subscriptionActivity): void
    {}

    /**
     * @inheritDoc
     */
    public function getLog(?string $streamId): array
    {
        return [];
    }
}
