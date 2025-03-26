<?php

namespace Saucy\Core\Subscriptions;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final class RunAllSubscriptionsInSync
{
    private array $subscriptions = [];

    public function __construct(
        private bool $runSync,
    ) {}

    public function runInSync(bool $runSync): void
    {
        $this->runSync = $runSync;
    }

    public function runSubscriptionInSync(string|array $subscriptionClasses): void
    {
        $subscriptionClasses = is_array($subscriptionClasses) ? $subscriptionClasses : [$subscriptionClasses];

        foreach ($subscriptionClasses as $subscriptionClass) {
            $this->subscriptions[] = $subscriptionClass;
        }
    }

    public function clearRunInSync(string|array $subscriptionClasses): void
    {
        $subscriptionClasses = is_array($subscriptionClasses) ? $subscriptionClasses : [$subscriptionClasses];

        foreach ($subscriptionClasses as $subscriptionClass) {
            $this->subscriptions = array_diff($this->subscriptions, [$subscriptionClass]);
        }
    }

    public function isRunSync(?MessageConsumer $messageConsumer = null): bool
    {
        if ($this->runSync) {
            return true;
        }

        if ($messageConsumer === null) {
            return false;
        }

        if (in_array(get_class($messageConsumer), $this->subscriptions)) {
            return true;
        }
        return false;
    }
}
