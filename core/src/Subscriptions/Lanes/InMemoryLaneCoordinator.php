<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

final class InMemoryLaneCoordinator implements LaneCoordinator
{
    /** @var array<string, int> */
    private array $versions = [];

    /** @var array<string, int> */
    private array $acknowledged = [];

    /** @var array<string, bool> */
    private array $structuralPending = [];

    /** @var array<string, array<string, true>> */
    private array $claimed = [];

    public function read(string $lane): LaneCoordinationState
    {
        return new LaneCoordinationState(
            membershipVersion: $this->versions[$lane] ?? 0,
            structuralPending: $this->structuralPending[$lane] ?? false,
            acknowledgedVersion: $this->acknowledged[$lane] ?? 0,
            claimedMembers: array_keys($this->claimed[$lane] ?? []),
        );
    }

    public function bumpMembership(string $lane, bool $structural = true): int
    {
        $version = ($this->versions[$lane] ?? 0) + 1;
        $this->versions[$lane] = $version;

        if ($structural) {
            $this->structuralPending[$lane] = true;
        }

        return $version;
    }

    public function structuralPending(string $lane): bool
    {
        return $this->structuralPending[$lane] ?? false;
    }

    public function membershipVersion(string $lane): int
    {
        return $this->versions[$lane] ?? 0;
    }

    public function acknowledge(string $lane, int $version): void
    {
        $this->acknowledged[$lane] = max($this->acknowledged[$lane] ?? 0, $version);

        // Only clear the flag when no newer bump arrived after the version being acknowledged.
        if (($this->versions[$lane] ?? 0) <= $version) {
            $this->structuralPending[$lane] = false;
        }
    }

    public function acknowledgedVersion(string $lane): int
    {
        return $this->acknowledged[$lane] ?? 0;
    }

    public function claim(string $lane, string $memberId): int
    {
        $this->claimed[$lane][$memberId] = true;

        return $this->bumpMembership($lane, structural: false);
    }

    public function release(string $lane, string $memberId): int
    {
        unset($this->claimed[$lane][$memberId]);

        return $this->bumpMembership($lane, structural: false);
    }

    public function claimedMembers(string $lane): array
    {
        return array_keys($this->claimed[$lane] ?? []);
    }
}
