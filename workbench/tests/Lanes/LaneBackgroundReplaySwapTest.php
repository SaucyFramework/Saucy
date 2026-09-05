<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use DateTime;
use Illuminate\Support\Facades\Bus;
use Saucy\Core\Projections\Replay\BackgroundReplayManager;
use Saucy\Core\Projections\Replay\BackgroundReplayStore;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Symfony\Component\Uid\Ulid;
use Workbench\Tests\Lanes\Fixtures\LaneAdvancingCoordinator;
use Workbench\Tests\Lanes\Fixtures\ReplayableConsumer;

final class LaneBackgroundReplaySwapTest extends LaneTestCase
{
    /** @test case 14: the hot swap waits for the lane to acknowledge before swapping tables */
    public function swap_replay_quiesces_the_member_through_the_lane_before_swapping(): void
    {
        Bus::fake();

        $this->insertEvent('type.a'); // 1

        $consumer = new ReplayableConsumer();
        $pump = new LaneAdvancingCoordinator($this->coordinator);
        $config = new LaneConfig(name: 'default');

        $members = ['member_swap' => $this->member('member_swap', $consumer, ['type.a'])];

        $runner = $this->runner($members, $config, $pump);
        $runner->poll(30);
        $pump->runner = $runner;

        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $laneProcessManager = $this->laneProcessManager($laneRegistry, $pump);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry, $laneProcessManager);
        $runningProcesses = $this->app->make(RunningProcesses::class);

        // A lane process is running: without quiescing, the swap would race a page in flight.
        $this->holdLaneLease();

        $replayStore = $this->app->make(BackgroundReplayStore::class);
        $replayStore->start('member_swap');
        $this->checkpoints->store(new Checkpoint('replay__member_swap', 1));

        $manager = new BackgroundReplayManager(
            registry: $this->subscriptionRegistry($members),
            processManager: $processManager,
            runningProcesses: $runningProcesses,
            checkpointStore: $this->checkpoints,
            replayStore: $replayStore,
            laneRegistry: $laneRegistry,
            laneProcessManager: $laneProcessManager,
        );

        $versionBeforeSwap = $this->coordinator->membershipVersion('default');

        $manager->swapReplay('member_swap');

        // The quiesce bumped the membership version and waited for the lane to acknowledge it.
        $this->assertGreaterThanOrEqual(
            $versionBeforeSwap + 1,
            $this->coordinator->acknowledgedVersion('default'),
            'the lane acknowledged the quiesce before the tables were swapped',
        );
        $this->assertGreaterThan(0, $pump->polls);

        $this->assertContains('swap', $consumer->calls);
        $this->assertContains('cleanup', $consumer->calls);
        $this->assertSame(1, $this->checkpoints->positionOf('member_swap'));

        // The member is resumed and back under the lane afterwards.
        $this->assertFalse($runningProcesses->isPaused('member_swap'));
        $this->assertNull($replayStore->getStatus('member_swap'));
    }
}
