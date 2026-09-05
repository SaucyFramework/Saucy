<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\Bus;
use Saucy\Core\Subscriptions\AllStream\AllStreamPollSubscriptionJob;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LanePollJob;
use Workbench\Tests\Lanes\Fixtures\LaneAdvancingCoordinator;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

/**
 * `quiesceMember()` is what every operator action (replay, hot swap) relies on to know the lane
 * is not mid-page for a member. It pauses the member, so a failure that leaves the pause in
 * place would take the projector down until somebody noticed.
 */
final class LaneQuiesceTest extends LaneTestCase
{
    /** @test item 2: a lane that never acknowledges must not leave the member paused */
    public function a_quiesce_that_times_out_undoes_its_own_pause(): void
    {
        Bus::fake();

        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $laneRegistry = $this->laneRegistry($members, ['default' => new LaneConfig(name: 'default', quiesceWaitInSeconds: 0)]);
        $manager = $this->laneProcessManager($laneRegistry);

        // A lane process is running and nothing ever acknowledges.
        $this->holdLaneLease();

        $runningProcesses = $this->app->make(RunningProcesses::class);

        try {
            $manager->quiesceMember('member_a', 'paused for replay');
            $this->fail('quiesceMember should have given up waiting for the lane');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not acknowledge', $e->getMessage());
        }

        $this->assertFalse(
            $runningProcesses->isPaused('member_a'),
            'the member must not be left paused when the quiesce fails',
        );
    }

    /** @test a pause somebody else took is left in place */
    public function a_failed_quiesce_leaves_a_pre_existing_pause_alone(): void
    {
        Bus::fake();

        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $laneRegistry = $this->laneRegistry($members, ['default' => new LaneConfig(name: 'default', quiesceWaitInSeconds: 0)]);
        $manager = $this->laneProcessManager($laneRegistry);

        $runningProcesses = $this->app->make(RunningProcesses::class);
        $runningProcesses->pause('member_a', 'paused by an operator');
        $this->holdLaneLease();

        try {
            $manager->quiesceMember('member_a', 'paused for replay');
            $this->fail('quiesceMember should have given up waiting for the lane');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue($runningProcesses->isPaused('member_a'), 'somebody else owns this pause');
    }

    /** @test item 2: a failing replay must not leave the projector paused either */
    public function replay_subscription_resumes_the_member_when_the_quiesce_fails(): void
    {
        Bus::fake();

        $consumer = new RecordingConsumer();
        $members = ['member_a' => $this->member('member_a', $consumer, ['type.a'])];
        $config = new LaneConfig(name: 'default', quiesceWaitInSeconds: 0);
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $laneProcessManager = $this->laneProcessManager($laneRegistry);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry, $laneProcessManager);

        $this->holdLaneLease();

        try {
            $processManager->replaySubscription('member_a');
            $this->fail('replaySubscription should have propagated the quiesce failure');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(
            $this->app->make(RunningProcesses::class)->isPaused('member_a'),
            'a failed replay must never leave the projector paused forever',
        );
    }

    /** @test the happy path: the lane acknowledges and the replay resets the checkpoint */
    public function a_replay_quiesces_the_member_resets_it_and_hands_it_back_to_the_lane(): void
    {
        Bus::fake();

        $this->insertEvent('type.a'); // 1
        $this->insertEvent('type.a'); // 2

        $consumer = new RecordingConsumer();
        $advancing = new LaneAdvancingCoordinator($this->coordinator);
        $config = new LaneConfig(name: 'default');

        $members = ['member_a' => $this->member('member_a', $consumer, ['type.a'])];
        $runner = $this->runner($members, $config, $advancing);
        $runner->poll(30);
        $this->assertSame(2, $this->checkpoints->positionOf('member_a'));

        $advancing->runner = $runner;

        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $laneProcessManager = $this->laneProcessManager($laneRegistry, $advancing);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry, $laneProcessManager);

        $this->holdLaneLease();

        $processManager->replaySubscription('member_a');

        $this->assertSame(0, $this->checkpoints->positionOf('member_a'), 'the checkpoint was reset');
        $this->assertFalse($this->app->make(RunningProcesses::class)->isPaused('member_a'));
        $this->assertFalse(
            $this->app->make(RunningProcesses::class)->isActive('member_a'),
            'the quiesce lease was released',
        );
    }

    /** @test item 9: the cron must still start subscriptions that belong to no lane */
    public function starting_all_processes_covers_lanes_and_the_subscriptions_outside_them(): void
    {
        Bus::fake();

        $members = [
            'member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a']),
            // Background replay subscriptions are never lane members and would otherwise never
            // be started once lanes are enabled.
            'replay__member_a' => $this->member('replay__member_a', new RecordingConsumer(), ['type.a']),
        ];

        $laneRegistry = $this->laneRegistry($members, ['default' => new LaneConfig(name: 'default')]);

        $this->allStreamProcessManager($members, $laneRegistry)->startProcesses();

        Bus::assertDispatchedTimes(LanePollJob::class, 1);
        Bus::assertDispatchedTimes(AllStreamPollSubscriptionJob::class, 1);
        Bus::assertDispatched(
            AllStreamPollSubscriptionJob::class,
            static fn(AllStreamPollSubscriptionJob $job) => $job->subscriptionId === 'replay__member_a',
        );
    }
}
