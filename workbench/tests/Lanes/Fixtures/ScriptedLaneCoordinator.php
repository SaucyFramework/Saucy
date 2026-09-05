<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\Lanes\LaneCoordinationState;
use Saucy\Core\Subscriptions\Lanes\LaneCoordinator;

/**
 * Lets a test place another process's action at an exact point in the lane's poll.
 *
 * `$afterRead` fires once, immediately after the runner has read the coordinator row and before
 * it acknowledges — the window in which a concurrent sync claim is easiest to lose.
 */
final class ScriptedLaneCoordinator implements LaneCoordinator
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $afterRead = null;

    public int $reads = 0;

    private bool $running = false;

    public function __construct(private readonly LaneCoordinator $inner) {}

    public function read(string $lane): LaneCoordinationState
    {
        $this->reads++;
        $state = $this->inner->read($lane);

        if ($this->afterRead !== null && !$this->running) {
            $this->running = true;
            $hook = $this->afterRead;
            $this->afterRead = null;
            try {
                $hook();
            } finally {
                $this->running = false;
            }
        }

        return $state;
    }

    public function bumpMembership(string $lane, bool $structural = true): int
    {
        return $this->inner->bumpMembership($lane, $structural);
    }

    public function structuralPending(string $lane): bool
    {
        return $this->inner->structuralPending($lane);
    }

    public function membershipVersion(string $lane): int
    {
        return $this->inner->membershipVersion($lane);
    }

    public function acknowledge(string $lane, int $version): void
    {
        $this->inner->acknowledge($lane, $version);
    }

    public function acknowledgedVersion(string $lane): int
    {
        return $this->inner->acknowledgedVersion($lane);
    }

    public function claim(string $lane, string $memberId): int
    {
        return $this->inner->claim($lane, $memberId);
    }

    public function release(string $lane, string $memberId): int
    {
        return $this->inner->release($lane, $memberId);
    }

    public function claimedMembers(string $lane): array
    {
        return $this->inner->claimedMembers($lane);
    }
}
