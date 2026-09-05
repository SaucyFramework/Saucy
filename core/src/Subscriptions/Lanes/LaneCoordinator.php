<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

/**
 * Shared coordination state for a lane, on top of leases ({@see \Saucy\Core\Subscriptions\Infra\RunningProcesses})
 * and checkpoints ({@see \Saucy\Core\Subscriptions\Checkpoints\CheckpointStore}).
 *
 * A lane runner reads one coordinator row per page boundary, through {@see read()}. The
 * membership version tells it something changed; `structuralPending` tells it whether that
 * change needs a full membership evaluation (pause/resume/replay/poison) or only a re-read of
 * the sync-claimed set.
 *
 * Implementation contract:
 *
 * - `bumpMembership()`, `claim()` and `release()` MUST return the value of THEIR OWN increment,
 *   under a row lock, so that two concurrent bumps return two distinct versions. A caller waits
 *   for `acknowledgedVersion >= theVersionItGotBack`; returning a later version would make it
 *   wait for an acknowledgement that is already implied, and returning an earlier one would let
 *   it proceed while the lane is still mid-page.
 * - `acknowledge($lane, $version)` MUST only clear `structuralPending` when no bump landed after
 *   the acknowledged version (i.e. only when `membershipVersion <= $version`). Clearing it
 *   unconditionally would swallow a structural change that arrived while the lane was
 *   evaluating, and the lane would never re-evaluate for it.
 *
 * A host binding its own stores (DynamoDB, say) implements this with an
 * `UpdateItem ... ADD membership_version :one` plus `ReturnValues: UPDATED_NEW` for the counter,
 * and a string set for the claims.
 */
interface LaneCoordinator
{
    /** One consistent read of the whole row. Preferred over the granular getters. */
    public function read(string $lane): LaneCoordinationState;

    /**
     * Atomic increment returning THIS bump's version. MUST NOT be a read-then-write: two
     * concurrent bumps must yield two distinct versions.
     */
    public function bumpMembership(string $lane, bool $structural = true): int;

    /** True when a structural bump has not been acknowledged yet. Cleared by acknowledge(). */
    public function structuralPending(string $lane): bool;

    public function membershipVersion(string $lane): int;

    public function acknowledge(string $lane, int $version): void;

    public function acknowledgedVersion(string $lane): int;

    /**
     * Sync-claim set: members an inline run currently owns. Adding a member also performs a
     * claim bump (non-structural); the version of that bump is returned.
     */
    public function claim(string $lane, string $memberId): int;

    /** Removes a claim and performs the matching claim bump. Returns that bump's version. */
    public function release(string $lane, string $memberId): int;

    /** @return array<string> member ids */
    public function claimedMembers(string $lane): array;
}
