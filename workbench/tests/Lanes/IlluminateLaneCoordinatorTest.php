<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Subscriptions\Lanes\IlluminateLaneCoordinator;
use Saucy\Core\Subscriptions\Lanes\LaneCoordinator;
use Workbench\Tests\WithDatabaseTestCase;

final class IlluminateLaneCoordinatorTest extends WithDatabaseTestCase
{
    private function coordinator(): LaneCoordinator
    {
        return new IlluminateLaneCoordinator(DB::connection());
    }

    /** @test */
    public function an_unknown_lane_reads_as_an_empty_state(): void
    {
        $state = $this->coordinator()->read('default');

        $this->assertSame(0, $state->membershipVersion);
        $this->assertSame(0, $state->acknowledgedVersion);
        $this->assertFalse($state->structuralPending);
        $this->assertSame([], $state->claimedMembers);
    }

    /** @test each bump must return the value of its OWN increment */
    public function bumps_return_distinct_increasing_versions(): void
    {
        $coordinator = $this->coordinator();

        $this->assertSame(1, $coordinator->bumpMembership('default'));
        $this->assertSame(2, $coordinator->bumpMembership('default'));
        $this->assertSame(3, $coordinator->bumpMembership('default', structural: false));
        $this->assertSame(3, $coordinator->membershipVersion('default'));

        // The row is created once, not once per bump.
        $this->assertSame(1, DB::table('lane_coordination')->where('lane', 'default')->count());
    }

    /** @test */
    public function a_structural_bump_sets_the_flag_and_a_claim_bump_does_not(): void
    {
        $coordinator = $this->coordinator();

        $coordinator->bumpMembership('default', structural: false);
        $this->assertFalse($coordinator->structuralPending('default'));

        $coordinator->bumpMembership('default', structural: true);
        $this->assertTrue($coordinator->structuralPending('default'));
    }

    /** @test */
    public function acknowledging_the_current_version_clears_the_structural_flag(): void
    {
        $coordinator = $this->coordinator();

        $version = $coordinator->bumpMembership('default', structural: true);
        $coordinator->acknowledge('default', $version);

        $this->assertFalse($coordinator->structuralPending('default'));
        $this->assertSame($version, $coordinator->acknowledgedVersion('default'));
    }

    /** @test the rule that keeps a structural change from being swallowed */
    public function acknowledging_an_older_version_must_not_clear_the_structural_flag(): void
    {
        $coordinator = $this->coordinator();

        $read = $coordinator->bumpMembership('default', structural: true);
        // A second structural bump lands while the lane is still evaluating for the first.
        $coordinator->bumpMembership('default', structural: true);

        $coordinator->acknowledge('default', $read);

        $this->assertTrue(
            $coordinator->structuralPending('default'),
            'a bump that landed after the acknowledged version must still force a re-evaluation',
        );
        $this->assertSame($read, $coordinator->acknowledgedVersion('default'));
    }

    /** @test */
    public function acknowledging_never_moves_the_acknowledged_version_backwards(): void
    {
        $coordinator = $this->coordinator();

        $coordinator->bumpMembership('default');
        $coordinator->bumpMembership('default');
        $coordinator->acknowledge('default', 2);
        $coordinator->acknowledge('default', 1);

        $this->assertSame(2, $coordinator->acknowledgedVersion('default'));
    }

    /** @test */
    public function claims_are_a_set_and_each_change_is_a_claim_bump(): void
    {
        $coordinator = $this->coordinator();

        $first = $coordinator->claim('default', 'member_a');
        $second = $coordinator->claim('default', 'member_b');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
        $this->assertNotSame($first, $second);

        $claimed = $coordinator->claimedMembers('default');
        sort($claimed);
        $this->assertSame(['member_a', 'member_b'], $claimed);

        // A claim change must not set the structural flag.
        $this->assertFalse($coordinator->structuralPending('default'));

        $third = $coordinator->release('default', 'member_a');
        $this->assertSame(3, $third);
        $this->assertSame(['member_b'], $coordinator->claimedMembers('default'));

        // Releasing a member that is not claimed is a no-op beyond the bump.
        $coordinator->release('default', 'member_a');
        $this->assertSame(['member_b'], $coordinator->claimedMembers('default'));
    }

    /** @test */
    public function lanes_are_independent_rows(): void
    {
        $coordinator = $this->coordinator();

        $coordinator->bumpMembership('default');
        $coordinator->claim('money', 'member_ledger');

        $this->assertSame(1, $coordinator->membershipVersion('default'));
        $this->assertSame([], $coordinator->claimedMembers('default'));
        $this->assertSame(['member_ledger'], $coordinator->claimedMembers('money'));
    }

    /** @test read() returns one consistent snapshot of the whole row */
    public function read_returns_the_full_state(): void
    {
        $coordinator = $this->coordinator();

        $coordinator->bumpMembership('default', structural: true);
        $coordinator->claim('default', 'member_a');
        $coordinator->acknowledge('default', 1);

        $state = $coordinator->read('default');

        $this->assertSame(2, $state->membershipVersion);
        $this->assertSame(1, $state->acknowledgedVersion);
        $this->assertTrue($state->structuralPending, 'the claim bump landed after the ack');
        $this->assertSame(['member_a'], $state->claimedMembers);
    }
}
