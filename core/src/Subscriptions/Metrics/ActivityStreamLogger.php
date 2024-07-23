<?php

namespace Saucy\Core\Subscriptions\Metrics;

interface ActivityStreamLogger
{
    public function log(SubscriptionActivity ...$subscriptionActivity): void;

    /**
     * @return array<SubscriptionActivity>
     */
    public function getLog(?string $streamId): array;

    public function purgeOld(\DateTime $before = null): void;
}
