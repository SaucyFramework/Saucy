<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Saucy\Core\Subscriptions\AllStream\AllStreamPollSubscriptionJob;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LanePollJob;
use Saucy\Core\Subscriptions\Lanes\LaneRegistry;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

final class LaneCatchUpAndRoutingTest extends LaneTestCase
{
    /** @test case 8: a member behind the catch-up window runs standalone */
    public function a_member_far_behind_the_window_is_handed_to_a_standalone_catch_up_job(): void
    {
        Bus::fake();

        foreach (range(1, 30) as $ignored) {
            $this->insertEvent('type.a');
        }

        $near = new RecordingConsumer();
        $behind = new RecordingConsumer();

        $members = [
            'member_near' => $this->member('member_near', $near, ['type.a']),
            'member_behind' => $this->member('member_behind', $behind, ['type.a']),
        ];

        $config = new LaneConfig(name: 'default', pageSize: 100, catchUpThreshold: 10);
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry);

        $this->setCheckpoint('member_near', 25);
        $this->setCheckpoint('member_behind', 2);

        $runner = $this->runner(
            $members,
            $config,
            startCatchUp: fn(string $memberId) => $processManager->startStandalone($memberId),
        );

        $runner->poll(30);

        Bus::assertDispatched(
            AllStreamPollSubscriptionJob::class,
            static fn(AllStreamPollSubscriptionJob $job) => $job->subscriptionId === 'member_behind',
        );
        Bus::assertDispatchedTimes(AllStreamPollSubscriptionJob::class, 1);

        $this->assertSame([], $behind->handled, 'the catch-up member is excluded from the lane');
        $this->assertSame(range(26, 30), $near->handled);
        // fromPosition is the min over the IN-LANE members, not over the catch-up member.
        $this->assertSame(25, $this->reader->queries[0]->fromPosition);

        // The catch-up job caught the member up, released its lease and bumped the lane.
        $this->checkpoints->store(new Checkpoint('member_behind', 30));
        DB::table('running_processes')->where('subscription_id', 'member_behind')->delete();
        $this->coordinator->bumpMembership('default');

        $this->insertEvent('type.a'); // 31
        $runner->poll(30);

        $this->assertSame([31], $behind->handled, 'the member is back in the lane');
    }

    /** @test case 11: trigger routing goes to lanes, and to legacy jobs when lanes are off */
    public function triggering_on_event_types_starts_one_lane_job_per_matching_lane(): void
    {
        Bus::fake();

        $members = [
            'member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a']),
            'member_b' => $this->member('member_b', new RecordingConsumer(), ['type.a']),
            'member_money' => $this->member('member_money', new RecordingConsumer(), ['type.money']),
        ];

        $laneRegistry = $this->laneRegistry(
            $members,
            [
                'default' => new LaneConfig(name: 'default'),
                'money' => new LaneConfig(name: 'money'),
            ],
            ['member_money' => 'money'],
        );

        $this->allStreamProcessManager($members, $laneRegistry)
            ->startProcessesThatRequireEvents(['type.a']);

        Bus::assertDispatchedTimes(LanePollJob::class, 1);
        Bus::assertDispatched(LanePollJob::class, static fn(LanePollJob $job) => $job->laneName === 'default');
        Bus::assertNotDispatched(AllStreamPollSubscriptionJob::class);
    }

    /** @test case 12: with saucy.lanes empty the legacy per-subscription path is unchanged */
    public function with_lanes_disabled_triggering_dispatches_the_legacy_poll_jobs(): void
    {
        Bus::fake();

        $members = [
            'member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a']),
            'member_b' => $this->member('member_b', new RecordingConsumer(), ['type.a']),
            'member_c' => $this->member('member_c', new RecordingConsumer(), ['type.b']),
        ];

        $laneRegistry = $this->laneRegistry($members); // no lanes configured

        $this->assertFalse($laneRegistry->enabled());
        $this->assertNull($laneRegistry->laneFor('member_a'));

        $this->allStreamProcessManager($members, $laneRegistry)
            ->startProcessesThatRequireEvents(['type.a']);

        Bus::assertNotDispatched(LanePollJob::class);
        Bus::assertDispatchedTimes(AllStreamPollSubscriptionJob::class, 2);
    }

    /** @test the container resolves a disabled lane registry when saucy.lanes is empty */
    public function the_default_configuration_leaves_lanes_disabled(): void
    {
        $this->assertSame([], config('saucy.lanes'));
        $this->assertFalse($this->app->make(LaneRegistry::class)->enabled());
    }
}
