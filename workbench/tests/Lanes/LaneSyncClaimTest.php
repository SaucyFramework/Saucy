<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use DateTime;
use Illuminate\Support\Facades\Bus;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LanePollJob;
use Symfony\Component\Uid\Ulid;
use Workbench\Tests\Lanes\Fixtures\CountingAllStreamReader;
use Workbench\Tests\Lanes\Fixtures\HookedAllStreamReader;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;
use Workbench\Tests\Lanes\Fixtures\ScriptedLaneCoordinator;

/**
 * The sync-claim (`awaitProjection`) path, driven step by step so the interleavings between the
 * lane's page boundary and a request claiming a member are actually exercised.
 */
final class LaneSyncClaimTest extends LaneTestCase
{
    /** @test case 9a: the claim lands after the lane read the version but before it acknowledged */
    public function a_claim_landing_between_the_coordinator_read_and_the_acknowledgement_is_not_lost(): void
    {
        [$members, $a, $b, $runner, $scripted] = $this->laneAtPositionOne();

        $this->insertEvent('type.a'); // 2
        $claimVersion = null;

        // Another request claims member_b right after the lane read the coordinator row.
        $scripted->afterRead = function () use (&$claimVersion) {
            $claimVersion = $this->coordinator->claim('default', 'member_b');
        };

        $runner->poll(30);

        $this->assertNotNull($claimVersion);
        $this->assertLessThan(
            $claimVersion,
            $this->coordinator->acknowledgedVersion('default'),
            'the lane acknowledged the version it read, not the claim that landed after it',
        );

        // The inline caller is still waiting; the lane reaches its next boundary and acks.
        $runner->poll(30);
        $this->assertGreaterThanOrEqual($claimVersion, $this->coordinator->acknowledgedVersion('default'));

        // Only now does the inline poll run - and it must not handle anything twice.
        $handledBefore = $b->handled;
        $members['member_b']->poll();
        $this->assertSame($handledBefore, $b->handled, 'the inline run handled nothing twice');
        $this->assertSame([1, 2], $b->handled);
        $this->assertSame([1, 2], $a->handled);

        // After the release the lane takes the member back without replaying anything.
        $this->coordinator->release('default', 'member_b');
        $runner->poll(30);
        $this->assertSame([1, 2], $b->handled);
    }

    /** @test case 9b: the claim lands after the acknowledgement but before the page is read */
    public function a_claim_landing_between_the_acknowledgement_and_the_page_read_is_not_lost(): void
    {
        [$members, $a, $b, $runner] = $this->laneAtPositionOne(hookReader: true);

        $this->insertEvent('type.a'); // 2
        $claimVersion = null;

        /** @var HookedAllStreamReader $hooked */
        $hooked = $this->hookedReader;
        $hooked->beforePaginate = function () use (&$claimVersion) {
            $claimVersion = $this->coordinator->claim('default', 'member_b');
        };

        $runner->poll(30);

        $this->assertNotNull($claimVersion);
        $this->assertLessThan($claimVersion, $this->coordinator->acknowledgedVersion('default'));

        $runner->poll(30);
        $this->assertGreaterThanOrEqual($claimVersion, $this->coordinator->acknowledgedVersion('default'));

        $handledBefore = $b->handled;
        $members['member_b']->poll();
        $this->assertSame($handledBefore, $b->handled, 'the inline run handled nothing twice');
        $this->assertSame([1, 2], $b->handled);
        $this->assertSame([1, 2], $a->handled);
    }

    /** @test case 9c: with no lane process running the inline poll starts immediately */
    public function an_inline_run_does_not_wait_when_the_lane_is_not_running(): void
    {
        Bus::fake();

        [$members, , $b] = $this->laneAtPositionOne();
        $this->insertEvent('type.a'); // 2

        $config = new LaneConfig(name: 'default');
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        // A 0 second cap: if this waited at all, it would give up instead of polling.
        $manager = $this->laneProcessManager($laneRegistry, syncClaimTimeoutInSeconds: 0.0);

        $manager->runMemberInline($members['member_b']);

        $this->assertSame([1, 2], $b->handled, 'the inline poll ran');
        $this->assertSame([], $this->coordinator->claimedMembers('default'), 'the claim was released');
        $this->assertFalse($this->app->make(RunningProcesses::class)->isActive('member_b'));
    }

    /** @test case 9d: hitting the wait cap gives up cleanly and leaves the work to the lane */
    public function an_inline_run_that_is_never_acknowledged_gives_up_and_leaves_it_to_the_lane(): void
    {
        Bus::fake();

        [$members, , $b] = $this->laneAtPositionOne();
        $this->insertEvent('type.a'); // 2

        $config = new LaneConfig(name: 'default');
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $manager = $this->laneProcessManager($laneRegistry, syncClaimTimeoutInSeconds: 0.0);

        // A lane process is running and nothing ever acknowledges the claim.
        $this->holdLaneLease();

        $manager->runMemberInline($members['member_b']);

        $this->assertSame([1], $b->handled, 'the inline poll did not run');
        $this->assertSame([], $this->coordinator->claimedMembers('default'), 'the claim was released');
        $this->assertFalse(
            $this->app->make(RunningProcesses::class)->isActive('member_b'),
            'the member lease was stopped',
        );
        // The lane is already running, so it will pick the event up; nothing new is dispatched.
        Bus::assertNotDispatched(LanePollJob::class);
    }

    /** @test giving up because the member lease is taken must still wake the lane */
    public function an_inline_run_blocked_by_another_lease_starts_the_lane(): void
    {
        Bus::fake();

        [$members] = $this->laneAtPositionOne();
        $this->insertEvent('type.a'); // 2

        // Somebody else (a catch-up job) already owns the member.
        $this->app->make(RunningProcesses::class)->start(
            subscriptionId: 'member_b',
            processId: Ulid::generate(),
            expiresAt: (new DateTime('now'))->modify('+5 minutes'),
        );

        $config = new LaneConfig(name: 'default');
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);

        $this->laneProcessManager($laneRegistry)->runMemberInline($members['member_b']);

        Bus::assertDispatchedTimes(LanePollJob::class, 1);
    }

    /** @test item 3: a claim with no lease behind it is stale and must not evict the member */
    public function a_claim_whose_owner_died_is_released_and_the_member_stays_in_the_lane(): void
    {
        [$members, , $b, $runner] = $this->laneAtPositionOne();
        $this->insertEvent('type.a'); // 2

        // An inline run crashed (Lambda timeout) between claiming and its finally block: the
        // claim is in the coordinator but no lease backs it.
        $this->coordinator->claim('default', 'member_b');
        $this->coordinator->bumpMembership('default', structural: true);

        $runner->poll(30);

        $this->assertSame([1, 2], $b->handled, 'the member is not evicted by a stale claim');
        $this->assertSame([], $this->coordinator->claimedMembers('default'), 'the stale claim was released');
    }

    /** @test item 4: a structural bump during an inline run must not freeze the member */
    public function a_member_excluded_by_a_lease_is_re_admitted_once_the_lease_is_gone(): void
    {
        [$members, $a, $b, $runner] = $this->laneAtPositionOne();

        $runningProcesses = $this->app->make(RunningProcesses::class);
        $processId = Ulid::generate();

        // An inline run takes the lease first, then claims.
        $runningProcesses->start(
            subscriptionId: 'member_b',
            processId: $processId,
            expiresAt: (new DateTime('now'))->modify('+5 minutes'),
        );
        $this->coordinator->claim('default', 'member_b');

        // A structural bump lands mid-run (an operator resumed some other projector), so the
        // lane evaluates and sees member_b as lease-held rather than merely claimed.
        $this->coordinator->bumpMembership('default', structural: true);

        $this->insertEvent('type.a'); // 2
        $runner->poll(30);

        $this->assertSame([1, 2], $a->handled);
        $this->assertSame([1], $b->handled, 'member_b is out while the inline run owns it');

        // The inline run finishes: lease dropped, then the claim released (a claim bump).
        $runningProcesses->stop($processId);
        $this->coordinator->release('default', 'member_b');

        $this->insertEvent('type.a'); // 3
        $runner->poll(30);

        $this->assertSame([1, 2, 3], $b->handled, 'the member was re-admitted, not frozen');
    }

    /** @test a late release from a finished inline run must not re-admit a member a newer run owns */
    public function a_stale_release_does_not_let_the_lane_double_handle_with_a_live_inline_run(): void
    {
        [$members, $a, $b, $runner] = $this->laneAtPositionOne(hookReader: true);

        $runningProcesses = $this->app->make(RunningProcesses::class);

        // Inline run A takes the member lease and claims it.
        $processA = Ulid::generate();
        $runningProcesses->start('member_b', $processA, (new DateTime('now'))->modify('+5 minutes'));
        $this->coordinator->claim('default', 'member_b');

        $this->insertEvent('type.a'); // 2

        $runner->poll(30);
        $this->assertSame([1, 2], $a->handled);
        $this->assertSame([1], $b->handled, 'member_b is out while an inline run owns it');

        // A drops its lease. Inline run B slips into that gap: takes the lease and claims.
        $runningProcesses->stop($processA);
        $processB = Ulid::generate();
        $runningProcesses->start('member_b', $processB, (new DateTime('now'))->modify('+5 minutes'));
        $this->coordinator->claim('default', 'member_b');

        // Only now does A release. The claimed set is a plain set, so this deletes B's claim -
        // the member leaves the claimed set while B is still running.
        $this->coordinator->release('default', 'member_b');

        // B's inline poll runs while the lane is reading its page, which is exactly the window
        // in which a re-admitted member would be handed the same event the inline run has.
        $this->hookedReader->beforePaginate = static fn() => $members['member_b']->poll();

        $runner->poll(30);

        $this->assertSame([1, 2], $b->handled, 'every event handled exactly once');
        $this->assertSame(2, $this->checkpoints->positionOf('member_b'));

        // Once B really is done the lane takes the member back.
        $runningProcesses->stop($processB);
        $this->coordinator->release('default', 'member_b');

        $this->insertEvent('type.a'); // 3
        $runner->poll(30);
        $this->assertSame([1, 2, 3], $b->handled);
    }

    private ?HookedAllStreamReader $hookedReader = null;

    /**
     * Builds a two-member lane whose members have both handled event 1.
     *
     * @return array{array<string, \Saucy\Core\Subscriptions\AllStream\AllStreamSubscription>, RecordingConsumer, RecordingConsumer, \Saucy\Core\Subscriptions\Lanes\LaneRunner, ScriptedLaneCoordinator}
     */
    private function laneAtPositionOne(bool $hookReader = false): array
    {
        $this->insertEvent('type.a'); // 1

        if ($hookReader) {
            $this->hookedReader = new HookedAllStreamReader($this->app->make(\Saucy\MessageStorage\AllStreamReader::class));
            $this->reader = new CountingAllStreamReader($this->hookedReader);
        }

        $a = new RecordingConsumer();
        $b = new RecordingConsumer();

        $members = [
            'member_a' => $this->member('member_a', $a, ['type.a']),
            'member_b' => $this->member('member_b', $b, ['type.a']),
        ];

        $scripted = new ScriptedLaneCoordinator($this->coordinator);
        $runner = $this->runner($members, new LaneConfig(name: 'default'), $scripted);
        $runner->poll(30);

        return [$members, $a, $b, $runner, $scripted];
    }
}
