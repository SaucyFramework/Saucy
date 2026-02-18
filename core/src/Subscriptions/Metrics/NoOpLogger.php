<?php

namespace Saucy\Core\Subscriptions\Metrics;

final readonly class NoOpLogger implements ActivityStreamLogger
{
    public function log(SubscriptionActivity ...$subscriptionActivity): void {}

    /**
     * @inheritDoc
     */
    public function getLog(?string $streamId, int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getLogBetween(?string $streamId, \DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    public function purgeOld(\DateTime $before = null): void {}
}
