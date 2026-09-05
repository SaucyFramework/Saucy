<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\Lanes\LaneCoordinationState;
use Saucy\Core\Subscriptions\Lanes\LaneCoordinator;
use Saucy\Core\Subscriptions\Lanes\LaneRunner;

/**
 * Stands in for a lane worker running in ANOTHER process, for the operator-action tests
 * (`quiesceMember`, `swapReplay`) whose whole point is that they block until the lane
 * acknowledges. The test suite is single-threaded, so advancing the runner whenever the
 * acknowledged version is read is the only way to satisfy that wait.
 *
 * Deliberately NOT used by the sync-claim tests: those drive the runner and the claim step by
 * step so the real interleavings are exercised rather than papered over.
 */
final class LaneAdvancingCoordinator implements LaneCoordinator
{
    public ?LaneRunner $runner = null;

    public int $polls = 0;

    private bool $advancing = false;

    public function __construct(private readonly LaneCoordinator $inner) {}

    public function acknowledgedVersion(string $lane): int
    {
        if ($this->runner !== null && !$this->advancing) {
            $this->advancing = true;
            try {
                $this->polls++;
                $this->runner->poll(30);
            } finally {
                $this->advancing = false;
            }
        }

        return $this->inner->acknowledgedVersion($lane);
    }

    public function read(string $lane): LaneCoordinationState
    {
        return $this->inner->read($lane);
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
