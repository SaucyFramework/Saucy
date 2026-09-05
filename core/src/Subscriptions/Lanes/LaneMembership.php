<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;

/**
 * The outcome of one full membership evaluation for a lane.
 *
 * Every read the evaluation needs is done once for the whole lane: the unresolved poison
 * messages are fetched in a single call and grouped by subscription id, and the lane head comes
 * from `AllStreamReader::maxEventId()` rather than from the member positions (a member pinned
 * above the store head with `startFrom` would otherwise eject every other member).
 */
final readonly class LaneMembership
{
    /**
     * @param array<string, int> $positions member id => position read from its checkpoint store
     * @param array<string> $inLane members the lane runner dispatches to
     * @param array<string> $catchUp members that must run a standalone catch-up job
     * @param array<string, true> $eligibleExceptClaimed members inside the window whose ONLY
     *        exclusion reason is a sync claim or somebody else holding their lease. These are the
     *        members a claim-type bump may put back without a full re-evaluation.
     * @param array<string> $staleClaims claims whose claimer no longer holds the member's lease
     */
    public function __construct(
        public array $positions,
        public array $inLane,
        public array $catchUp,
        public array $eligibleExceptClaimed,
        public array $staleClaims,
        public int $laneHead,
    ) {}

    /**
     * @param array<string, AllStreamSubscription> $members
     * @param array<string, true> $claimed
     */
    public static function evaluate(
        array $members,
        array $claimed,
        int $laneHead,
        int $catchUpThreshold,
        RunningProcesses $runningProcesses,
        PoisonMessageStore $poisonMessageStore,
    ): self {
        $haltedSubscriptions = [];
        foreach ($poisonMessageStore->getUnresolved() as $poisonMessage) {
            $haltedSubscriptions[$poisonMessage->subscriptionId] = true;
        }

        $positions = [];
        $inLane = [];
        $catchUp = [];
        $eligibleExceptClaimed = [];
        $staleClaims = [];

        foreach ($members as $memberId => $member) {
            try {
                $positions[$memberId] = $member->checkpointStore->get($memberId)->position;
            } catch (CheckpointNotFound) {
                $positions[$memberId] = $member->streamOptions->startingFromPosition;
            }

            $halted = $member->failureMode === FailureMode::Halt && isset($haltedSubscriptions[$memberId]);

            if ($halted || $runningProcesses->isPaused($memberId)) {
                continue;
            }

            // Behind the catch-up window: hand the member to its own standalone poll job.
            // A member ABOVE the lane head is in-lane and simply skips everything.
            if ($positions[$memberId] < $laneHead - $catchUpThreshold) {
                $catchUp[] = $memberId;
                continue;
            }

            $isClaimed = isset($claimed[$memberId]);
            // A catch-up job, a sync claim or a legacy poll job holding the member's own lease.
            $leaseHeld = $runningProcesses->isActive($memberId);

            if ($leaseHeld) {
                // Claimed or not, somebody owns this member right now. It is inside the window,
                // so a claim-type bump can put it back without a full re-evaluation.
                $eligibleExceptClaimed[$memberId] = true;
                continue;
            }

            if ($isClaimed) {
                // A claim with no lease behind it: the inline run died between claiming and its
                // finally block. Claims have no TTL, so without this the member would be evicted
                // from the lane forever. Take it back and drop the claim.
                $staleClaims[] = $memberId;
            }

            $inLane[] = $memberId;
        }

        return new self($positions, $inLane, $catchUp, $eligibleExceptClaimed, $staleClaims, $laneHead);
    }
}
