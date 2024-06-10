<?php

namespace Saucy\Core\Subscriptions;

final class RunAllSubscriptionsInSync
{
    public function __construct(
        private bool $runSync,
    ) {}

    public function runInSync(bool $runSync): void
    {
        $this->runSync = $runSync;
    }

    public function isRunSync(): bool
    {
        return $this->runSync;
    }
}
