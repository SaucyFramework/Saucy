<?php

namespace Saucy\Core\Subscriptions\Metrics;

interface ActivityStreamLogger
{
    public function log(SubscriptionActivity ...$subscriptionActivity): void;

    /**
     * @return array<SubscriptionActivity>
     */
    public function getLog(?string $streamId, int $limit = 100, int $offset = 0): array;

    /**
     * @return array<SubscriptionActivity>
     */
    public function getLogBetween(?string $streamId, \DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array;

    public function purgeOld(\DateTime $before = null): void;
}
