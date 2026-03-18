<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\StreamSubscription\AggregateInstanceRepository;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscription;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;

final class SeedAggregateProjectorCheckpointsCommand extends Command
{
    protected $signature = 'saucy:seed-aggregate-checkpoints
        {subscriptionId? : Seed a specific subscription (omit to seed all)}
        {--force : Overwrite existing checkpoints}';

    protected $description = 'Seed aggregate projector checkpoints from the aggregate_instances table';

    public function handle(
        StreamSubscriptionRegistry $asyncRegistry,
        SyncStreamSubscriptionRegistry $syncRegistry,
        AggregateInstanceRepository $repository,
    ): int {
        /** @var string|null $targetId */
        $targetId = $this->argument('subscriptionId');

        $subscriptions = $this->collectSubscriptions($asyncRegistry, $syncRegistry);

        if ($targetId !== null) {
            if (!isset($subscriptions[$targetId])) {
                $this->error("Subscription '{$targetId}' not found.");
                $this->info('Available subscriptions: ' . implode(', ', array_keys($subscriptions)));
                return Command::FAILURE;
            }
            $subscriptions = [$targetId => $subscriptions[$targetId]];
        }

        if (count($subscriptions) === 0) {
            $this->info('No aggregate projector subscriptions found.');
            return Command::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $totalSeeded = 0;
        $totalSkipped = 0;

        foreach ($subscriptions as $id => $subscription) {
            $this->info("Seeding checkpoints for [{$id}] (aggregate type: {$subscription->aggregateType})...");

            $seeded = 0;
            $skipped = 0;

            foreach ($repository->getAllForType($subscription->aggregateType) as $instance) {
                $streamName = new AggregateStreamName($subscription->aggregateType, $instance->aggregateId);
                $checkpointKey = $subscription->getId($streamName);

                if (!$force) {
                    try {
                        $subscription->checkpointStore->get($checkpointKey);
                        $skipped++;
                        continue;
                    } catch (CheckpointNotFound) {
                        // No existing checkpoint, proceed to seed
                    }
                }

                $subscription->checkpointStore->store(
                    new Checkpoint($checkpointKey, $instance->streamPosition),
                );
                $seeded++;

                if (($seeded + $skipped) % 1000 === 0) {
                    $this->info("  Processed " . ($seeded + $skipped) . " instances...");
                }
            }

            $this->info("  Seeded: {$seeded}, Skipped (existing): {$skipped}");
            $totalSeeded += $seeded;
            $totalSkipped += $skipped;
        }

        $this->info("Done. Seeded {$totalSeeded} checkpoints, skipped {$totalSkipped} existing.");
        return Command::SUCCESS;
    }

    /**
     * @return array<string, StreamSubscription>
     */
    private function collectSubscriptions(
        StreamSubscriptionRegistry $asyncRegistry,
        SyncStreamSubscriptionRegistry $syncRegistry,
    ): array {
        $subscriptions = [];
        foreach ($asyncRegistry->streams as $id => $subscription) {
            $subscriptions[$id] = $subscription;
        }
        foreach ($syncRegistry->streams as $id => $subscription) {
            if (!isset($subscriptions[$id])) {
                $subscriptions[$id] = $subscription;
            }
        }
        return $subscriptions;
    }
}
