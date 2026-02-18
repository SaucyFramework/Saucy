<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Metrics\ProjectionSnapshot;
use Saucy\Core\Subscriptions\Metrics\ProjectionSnapshotStore;
use Saucy\MessageStorage\AllStreamReader;

final class SnapshotPositionsCommand extends Command
{
    /** @var string */
    protected $signature = 'saucy:snapshot-positions';

    /** @var string */
    protected $description = 'Record a snapshot of all subscription checkpoint positions';

    public function handle(
        CheckpointStore $checkpointStore,
        AllStreamReader $allStreamReader,
        ProjectionSnapshotStore $snapshotStore,
    ): int {
        $maxPosition = $allStreamReader->maxEventId();
        $checkpoints = $checkpointStore->getAll();
        $now = new \DateTime('now');

        if (count($checkpoints) === 0) {
            $this->info('No checkpoints found.');
            return Command::SUCCESS;
        }

        $snapshots = array_map(
            fn($checkpoint) => new ProjectionSnapshot(
                streamId: $checkpoint->streamIdentifier,
                position: $checkpoint->position,
                maxPosition: $maxPosition,
                recordedAt: $now,
            ),
            $checkpoints,
        );

        $snapshotStore->record(...$snapshots);

        $this->info(sprintf('Recorded %d snapshot(s) at max position %d.', count($snapshots), $maxPosition));

        return Command::SUCCESS;
    }
}
