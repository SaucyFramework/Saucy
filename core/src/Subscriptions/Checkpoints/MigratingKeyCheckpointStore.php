<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Checkpoints;

/**
 * Transparently migrates checkpoint keys from an old subscription ID to a new one.
 *
 * On get(): tries the new key first. On miss, tries the old key prefix.
 * If found under the old key, stores under the new key and deletes the old one.
 * All writes go to the new key only.
 */
final readonly class MigratingKeyCheckpointStore implements CheckpointStore
{
    public function __construct(
        private CheckpointStore $inner,
        private string $oldSubscriptionId,
        private string $newSubscriptionId,
    ) {}

    public function get(string $streamIdentifier): Checkpoint
    {
        try {
            return $this->inner->get($streamIdentifier);
        } catch (CheckpointNotFound) {
            // Not found under new key, try old key
        }

        $oldKey = $this->oldSubscriptionId . substr($streamIdentifier, strlen($this->newSubscriptionId));

        // Will throw CheckpointNotFound if not found under old key either
        $oldCheckpoint = $this->inner->get($oldKey);

        // Migrate: store under new key, remove old key
        $newCheckpoint = new Checkpoint($streamIdentifier, $oldCheckpoint->position);
        $this->inner->store($newCheckpoint);
        $this->inner->delete($oldKey);

        return $newCheckpoint;
    }

    public function store(Checkpoint $checkpoint): void
    {
        $this->inner->store($checkpoint);
    }

    public function delete(string $streamIdentifier): void
    {
        $this->inner->delete($streamIdentifier);
    }

    /**
     * @return array<Checkpoint>
     */
    public function getAll(): array
    {
        return $this->inner->getAll();
    }
}
