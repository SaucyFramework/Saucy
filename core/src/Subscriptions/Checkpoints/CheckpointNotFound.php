<?php

namespace Saucy\Core\Subscriptions\Checkpoints;

final class CheckpointNotFound extends \Exception
{
    public static function forStream(string $streamIdentifier): self
    {
        return new self("Checkpoint not found for stream {$streamIdentifier}");
    }
}
