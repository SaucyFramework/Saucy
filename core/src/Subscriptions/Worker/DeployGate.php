<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * The code version workers may run. A deploy writes it with `paused: true` before
 * migrations; the first worker on that version lifts the pause. A worker on any other version
 * takes no locks.
 */
final readonly class DeployGate
{
    public function __construct(
        public string $sha,
        public bool $paused,
        /** Event-store head when the deploy paused the workers; new workers look back to it. */
        public ?int $pausedAt = null,
        public ?int $pausedAtTime = null,
    ) {}

    public function admits(?string $sha): bool
    {
        return $sha === $this->sha;
    }
}
