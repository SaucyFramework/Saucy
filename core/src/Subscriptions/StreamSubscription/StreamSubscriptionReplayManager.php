<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Saucy\Core\Events\Streams\AggregateStreamName;

final readonly class StreamSubscriptionReplayManager
{
    public function __construct(
        private StreamSubscriptionRegistry $streamSubscriptionRegistry,
        private SyncStreamSubscriptionRegistry $syncStreamSubscriptionRegistry,
        private AggregateInstanceRepository $aggregateInstanceRepository,
    ) {}

    /**
     * Replay a single aggregate for a specific subscription.
     * Runs inline (no background job needed).
     */
    public function replayStream(string $subscriptionId, AggregateStreamName $streamName): void
    {
        $subscription = $this->resolveSubscription($subscriptionId);
        $subscription->prepareForReplay($streamName);
        $subscription->poll($streamName);
    }

    /**
     * Replay ALL aggregates for a specific subscription.
     * Dispatches a Bus::batch() where each job resets and replays its own aggregate stream.
     */
    public function replayAll(string $subscriptionId, ?string $queue = null): Batch
    {
        $subscription = $this->resolveSubscription($subscriptionId);

        $jobs = [];
        foreach ($this->aggregateInstanceRepository->getAllForType($subscription->aggregateType) as $instance) {
            $jobs[] = new StreamReplayJob(
                subscriptionId: $subscriptionId,
                aggregateType: $subscription->aggregateType,
                aggregateId: $instance->aggregateId,
            );
        }

        return Bus::batch($jobs)
            ->name("replay-all:{$subscriptionId}")
            ->onQueue($queue ?? $subscription->streamOptions->queue)
            ->dispatch();
    }

    /**
     * Trigger polling for ALL known aggregates of a subscription.
     * Does NOT reset data - just ensures all aggregates are caught up.
     */
    public function triggerAll(string $subscriptionId, ?string $queue = null): Batch
    {
        $subscription = $this->resolveSubscription($subscriptionId);

        $jobs = [];
        foreach ($this->aggregateInstanceRepository->getAllForType($subscription->aggregateType) as $instance) {
            $jobs[] = new StreamTriggerJob(
                subscriptionId: $subscriptionId,
                aggregateType: $subscription->aggregateType,
                aggregateId: $instance->aggregateId,
            );
        }

        return Bus::batch($jobs)
            ->name("trigger-all:{$subscriptionId}")
            ->onQueue($queue ?? $subscription->streamOptions->queue)
            ->dispatch();
    }

    private function resolveSubscription(string $subscriptionId): StreamSubscription
    {
        try {
            return $this->streamSubscriptionRegistry->get($subscriptionId);
        } catch (\Exception) {
            return $this->syncStreamSubscriptionRegistry->get($subscriptionId);
        }
    }
}
