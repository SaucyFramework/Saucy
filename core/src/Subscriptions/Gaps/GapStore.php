<?php

namespace Saucy\Core\Subscriptions\Gaps;

use DateTimeImmutable;

/**
 * Tracks per-subscription gaps in the all-stream `global_position` sequence.
 *
 * The all-stream auto-increment column reserves values at INSERT time but
 * rows only become visible at COMMIT — so under concurrent writers a poll
 * can observe position N+1 without ever seeing N. Without memory of the
 * skipped position the checkpoint advances past N and the event is lost.
 *
 * `GapStore` is that memory: missing positions are recorded and re-checked
 * on subsequent polls until they either resolve (the row commits) or
 * expire (the writer's transaction rolled back, so the gap is permanent).
 *
 * Implementations MUST make {@see commit()} atomic across the checkpoint
 * advance, gap inserts, and gap deletes so a partial write can never leave
 * the checkpoint past an unrecorded gap.
 */
interface GapStore
{
    /**
     * @return array<Gap>
     */
    public function getOpen(string $subscriptionId): array;

    /**
     * Atomic: advance the subscription checkpoint to `$newCheckpointPosition`,
     * insert `$newGapPositions`, delete `$closedGapPositions`.
     *
     * @param array<int> $newGapPositions
     * @param array<int> $closedGapPositions positions to remove from the
     *                                       gap store (resolved or expired)
     */
    public function commit(
        string $subscriptionId,
        int $newCheckpointPosition,
        array $newGapPositions,
        array $closedGapPositions,
        DateTimeImmutable $now,
    ): void;

    public function deleteAll(string $subscriptionId): void;
}
