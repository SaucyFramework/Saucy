<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use DateTime;
use Illuminate\Support\Facades\Bus;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LanePollJob;
use Symfony\Component\Uid\Ulid;
use Workbench\Tests\Lanes\Fixtures\RecordingConsumer;

final class LanePollJobTest extends LaneTestCase
{
    /** @test case 10a: a job inside the lease headroom chains a fresh one */
    public function it_self_chains_before_its_lease_runs_out(): void
    {
        Bus::fake();

        $config = new LaneConfig(name: 'default', processTimeoutInSeconds: 240, keepAliveInSeconds: 0);
        // Less than the 30 s headroom the lane job keeps, so it must hand over rather than poll.
        $processId = $this->startLaneProcess(secondsLeft: 20);

        $this->handleJob(new LanePollJob('default', $processId), $config);

        Bus::assertDispatchedTimes(LanePollJob::class, 1);
        Bus::assertDispatched(LanePollJob::class, static function (LanePollJob $job) use ($processId) {
            return $job->laneName === 'default' && $job->processId !== $processId;
        });

        $running = $this->app->make(RunningProcesses::class);
        $this->assertTrue($running->isActive('lane__default'), 'the fresh lease was taken');
        $this->assertFalse($running->isActive('lane__default', $processId), 'the old lease was released');
    }

    /** @test case 10b: an idle job with keep_alive_seconds = 0 stops without re-dispatching */
    public function an_idle_job_stops_without_re_dispatching_when_keep_alive_is_zero(): void
    {
        Bus::fake();

        $config = new LaneConfig(name: 'default', processTimeoutInSeconds: 240, keepAliveInSeconds: 0);
        $processId = $this->startLaneProcess(secondsLeft: 120);

        $this->handleJob(new LanePollJob('default', $processId), $config);

        Bus::assertNotDispatched(LanePollJob::class);
        $this->assertFalse($this->app->make(RunningProcesses::class)->isActive('lane__default'));
    }

    /** @test item 5: the job must never be retried while it still holds the lane lease */
    public function it_is_dispatched_with_a_single_try_and_a_timeout_past_the_lease(): void
    {
        Bus::fake();

        $config = new LaneConfig(name: 'default', processTimeoutInSeconds: 240);
        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);

        $this->laneProcessManager($laneRegistry)->startLaneIfNotRunning('default');

        Bus::assertDispatched(LanePollJob::class, static function (LanePollJob $job) {
            return $job->tries === 1 && $job->timeout === 270;
        });
    }

    /** @test item 12: a lane that is not configured still releases its lease */
    public function an_unknown_lane_releases_the_lease_instead_of_leaving_it_to_expire(): void
    {
        Bus::fake();

        // The lane the job names is not in the registry any more (renamed or removed in config).
        $processId = $this->startLaneProcess(secondsLeft: 120, lane: 'money');
        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $laneRegistry = $this->laneRegistry($members, ['default' => new LaneConfig(name: 'default')]);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry);

        $thrown = null;
        try {
            (new LanePollJob('money', $processId))->handle(
                $laneRegistry,
                $this->app->make(RunningProcesses::class),
                $this->laneRunnerFactory($laneRegistry, $processManager),
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('Lane not configured', $thrown->getMessage());
        $this->assertFalse(
            $this->app->make(RunningProcesses::class)->isActive('lane__money'),
            'the lease is released rather than left to expire',
        );
    }

    /** @test the job exposes the lane in its display name and tags */
    public function it_describes_itself_by_lane(): void
    {
        $job = new LanePollJob('money', 'process-1');

        $this->assertSame('projection lane: money', $job->displayName());
        $this->assertSame(
            ['projection-lane', 'lane:money', 'processId' => 'process-1'],
            $job->tags(),
        );
    }

    private function startLaneProcess(int $secondsLeft, string $lane = 'default'): string
    {
        $processId = Ulid::generate();

        $this->app->make(RunningProcesses::class)->start(
            subscriptionId: 'lane__' . $lane,
            processId: $processId,
            expiresAt: (new DateTime('now'))->modify("+{$secondsLeft} seconds"),
        );

        return $processId;
    }

    private function handleJob(LanePollJob $job, LaneConfig $config): void
    {
        $members = ['member_a' => $this->member('member_a', new RecordingConsumer(), ['type.a'])];
        $laneRegistry = $this->laneRegistry($members, ['default' => $config]);
        $processManager = $this->allStreamProcessManager($members, $laneRegistry);

        $job->handle(
            $laneRegistry,
            $this->app->make(RunningProcesses::class),
            $this->laneRunnerFactory($laneRegistry, $processManager),
        );
    }
}
