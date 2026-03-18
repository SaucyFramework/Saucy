<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\StreamSubscription;

use DateInterval;
use DateTime;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Symfony\Component\Uid\Ulid;

final class StreamReplayJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subscriptionId,
        public string $aggregateType,
        public string $aggregateId,
    ) {}

    public function handle(
        StreamSubscriptionRegistry $asyncRegistry,
        SyncStreamSubscriptionRegistry $syncRegistry,
        RunningProcesses $runningProcesses,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $subscription = $this->resolveSubscription($asyncRegistry, $syncRegistry);
        $streamName = new AggregateStreamName($this->aggregateType, $this->aggregateId);
        $streamId = $subscription->getId($streamName);

        $processId = Ulid::generate();
        try {
            $runningProcesses->start(
                $streamId,
                $processId,
                (new DateTime('now'))->add(new DateInterval('PT5M')),
            );
        } catch (StartProcessException) {
            return;
        }

        try {
            $subscription->prepareForReplay($streamName);
            $subscription->poll($streamName);
        } finally {
            $runningProcesses->stop($processId);
        }
    }

    public function displayName(): string
    {
        return "stream-replay: {$this->subscriptionId} - {$this->aggregateId}";
    }

    /**
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'stream-replay',
            'subscription:' . $this->subscriptionId,
            'aggregate:' . $this->aggregateId,
        ];
    }

    private function resolveSubscription(
        StreamSubscriptionRegistry $asyncRegistry,
        SyncStreamSubscriptionRegistry $syncRegistry,
    ): StreamSubscription {
        try {
            return $asyncRegistry->get($this->subscriptionId);
        } catch (\RuntimeException) {
            return $syncRegistry->get($this->subscriptionId);
        }
    }
}
