<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;

final class RecordingCheckpointStore implements CheckpointStore
{
    /** @var array<string, int> */
    private array $positions = [];

    /** @var array<int, array{string, int}> every store() call, in order */
    public array $writes = [];

    public function get(string $streamIdentifier): Checkpoint
    {
        if (!array_key_exists($streamIdentifier, $this->positions)) {
            throw CheckpointNotFound::forStream($streamIdentifier);
        }

        return new Checkpoint($streamIdentifier, $this->positions[$streamIdentifier]);
    }

    public function store(Checkpoint $checkpoint): void
    {
        $this->writes[] = [$checkpoint->streamIdentifier, $checkpoint->position];
        $this->positions[$checkpoint->streamIdentifier] = $checkpoint->position;
    }

    public function delete(string $streamIdentifier): void
    {
        unset($this->positions[$streamIdentifier]);
    }

    /**
     * @return array<Checkpoint>
     */
    public function getAll(): array
    {
        $checkpoints = [];
        foreach ($this->positions as $id => $position) {
            $checkpoints[] = new Checkpoint($id, $position);
        }

        return $checkpoints;
    }

    public function positionOf(string $streamIdentifier): ?int
    {
        return $this->positions[$streamIdentifier] ?? null;
    }

    /**
     * @return array<int, int> positions written for a single subscription, in order
     */
    public function writesFor(string $streamIdentifier): array
    {
        $writes = [];
        foreach ($this->writes as [$id, $position]) {
            if ($id === $streamIdentifier) {
                $writes[] = $position;
            }
        }

        return $writes;
    }
}
