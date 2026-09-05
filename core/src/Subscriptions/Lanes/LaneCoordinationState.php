<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

/**
 * One consistent read of a lane's coordination row.
 *
 * The lane runner reads this ONCE per page boundary; splitting it into separate getters is what
 * turned a single round-trip into three.
 */
final readonly class LaneCoordinationState
{
    /**
     * @param array<string> $claimedMembers
     */
    public function __construct(
        public int $membershipVersion,
        public bool $structuralPending,
        public int $acknowledgedVersion,
        public array $claimedMembers,
    ) {}

    public static function empty(): self
    {
        return new self(0, false, 0, []);
    }
}
