<?php

namespace Saucy\Core\Subscriptions\Checkpoints;

final readonly class Checkpoint
{
    public function __construct(
        public string $streamIdentifier,
        public int $position,
    ) {}

    public function withPosition(int $position): self
    {
        return new self($this->streamIdentifier, $position);
    }
}
