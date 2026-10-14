<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Decides which worker owns a subscription: rendezvous (highest-random-weight) hashing.
 *
 * Every worker computes the same answer from the same member list without talking to the others,
 * the subscriptions spread evenly, and when a worker joins or leaves only the subscriptions it gains
 * or loses move. The lock still guards correctness: while two workers briefly disagree about the
 * member list, the one that does not get the lock waits.
 */
final class RendezvousAssignment
{
    /**
     * @param list<string> $workerIds
     */
    public static function owner(string $key, array $workerIds): ?string
    {
        $owner = null;
        $best = '';
        foreach ($workerIds as $workerId) {
            $weight = hash('xxh3', $key . '|' . $workerId);
            if ($owner === null || $weight > $best || ($weight === $best && $workerId > $owner)) {
                $owner = $workerId;
                $best = $weight;
            }
        }

        return $owner;
    }
}
