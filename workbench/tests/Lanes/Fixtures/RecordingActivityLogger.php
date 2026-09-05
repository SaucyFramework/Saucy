<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\SubscriptionActivity;

final class RecordingActivityLogger implements ActivityStreamLogger
{
    /** @var array<int, SubscriptionActivity> */
    public array $activities = [];

    public function log(SubscriptionActivity ...$subscriptionActivity): void
    {
        foreach ($subscriptionActivity as $activity) {
            $this->activities[] = $activity;
        }
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_map(static fn(SubscriptionActivity $a) => $a->type, $this->activities);
    }

    /**
     * @inheritDoc
     */
    public function getLog(?string $streamId, int $limit = 100, int $offset = 0): array
    {
        return $this->activities;
    }

    /**
     * @inheritDoc
     */
    public function getLogBetween(?string $streamId, \DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array
    {
        return $this->activities;
    }

    public function purgeOld(\DateTime $before = null): void {}
}
