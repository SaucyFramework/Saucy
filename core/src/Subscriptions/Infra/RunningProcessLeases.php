<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Infra;

/**
 * Long-held locks for the projection worker.
 *
 * A queue job takes a lock for one poll and gives it back. The worker holds one for minutes, so it
 * takes a short lease and keeps pushing the expiry forward. The lock stays a normal running process:
 * queue jobs, the dashboard and replays see it as one.
 */
interface RunningProcessLeases
{
    /**
     * Moves the expiry of `subscriptionId`'s lock, but only while `processId` still holds it.
     *
     * @param array<string, string> $attributes extra fields stored on the lock where the backend
     *     supports them (e.g. the worker id and code version)
     * @return bool false when the lock is gone or belongs to another process
     */
    public function renew(string $subscriptionId, string $processId, \DateTimeInterface $expiresAt, array $attributes = []): bool;

    /**
     * Removes `subscriptionId`'s lock if it expired before `$cutoff` (its holder died), so it can be
     * taken without waiting for the grace period start() applies.
     *
     * @return bool whether a lock was removed
     */
    public function takeOverExpired(string $subscriptionId, \DateTimeInterface $cutoff): bool;
}
