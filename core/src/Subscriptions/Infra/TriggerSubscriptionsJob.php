<?php

namespace Saucy\Core\Subscriptions\Infra;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;

final class TriggerSubscriptionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string> $eventTypes
     */
    public function __construct(
        public array $eventTypes,
        public ?string $aggregateRootType = null,
        public ?string $aggregateRootId = null,
    ) {}

    public function handle(
        AllStreamSubscriptionProcessManager $allStreamSubscriptionProcessManager,
        StreamSubscriptionProcessManager $streamSubscriptionProcessManager,
    ): void {
        $allStreamSubscriptionProcessManager->startProcessesThatRequireEvents($this->eventTypes);

        if ($this->aggregateRootType !== null && $this->aggregateRootId !== null) {
            $streamName = new AggregateStreamName(
                aggregateRootType: $this->aggregateRootType,
                aggregateRootId: $this->aggregateRootId,
            );
            $streamSubscriptionProcessManager->startProcessesForAggregateInstance($streamName, $this->eventTypes);
        }
    }

    public function displayName(): string
    {
        return 'trigger-subscriptions';
    }

    /** @return array<string> */
    public function tags(): array
    {
        return ['trigger-subscriptions'];
    }
}
