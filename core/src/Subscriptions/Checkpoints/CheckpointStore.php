<?php

namespace Saucy\Core\Subscriptions\Checkpoints;

interface CheckpointStore
{
    /**
     * @param string $streamIdentifier
     * @throws CheckpointNotFound
     * @return Checkpoint
     */
    public function get(string $streamIdentifier): Checkpoint;

    public function store(Checkpoint $checkpoint): void;
}
