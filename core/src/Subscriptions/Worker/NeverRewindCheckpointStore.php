<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;

/**
 * Keeps a worker poll from moving a checkpoint backwards.
 *
 * After a poll that handled nothing, Saucy stores `maxEventId()`. Through BoundedAllStreamReader
 * that is the worker's safe head, which can be BELOW the checkpoint. That happens when a queue job or
 * an inline awaitProjection run already went past a hole the worker is still waiting on. Storing it
 * would re-deliver everything in between, reactors included. So a store lower than the position this
 * poll read is dropped.
 *
 * Only the worker's own subscription copies use this. Replays reset checkpoints through the
 * registry's subscriptions and never pass through here.
 */
final class NeverRewindCheckpointStore implements CheckpointStore
{
    /** @var array<string, int> */
    private array $read = [];

    public function __construct(
        private readonly CheckpointStore $inner,
    ) {}

    public function get(string $streamIdentifier): Checkpoint
    {
        unset($this->read[$streamIdentifier]);
        $checkpoint = $this->inner->get($streamIdentifier);
        $this->read[$streamIdentifier] = $checkpoint->position;

        return $checkpoint;
    }

    public function store(Checkpoint $checkpoint): void
    {
        if ($checkpoint->position < ($this->read[$checkpoint->streamIdentifier] ?? PHP_INT_MIN)) {
            return;
        }

        $this->inner->store($checkpoint);
        $this->read[$checkpoint->streamIdentifier] = $checkpoint->position;
    }

    public function delete(string $streamIdentifier): void
    {
        unset($this->read[$streamIdentifier]);
        $this->inner->delete($streamIdentifier);
    }

    public function getAll(): array
    {
        return $this->inner->getAll();
    }
}
