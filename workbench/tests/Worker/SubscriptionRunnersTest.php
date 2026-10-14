<?php

namespace Workbench\Tests\Worker;

use PHPUnit\Framework\TestCase;
use Saucy\Core\Subscriptions\Worker\InMemoryWorkerCoordination;
use Saucy\Core\Subscriptions\Worker\Runner;
use Saucy\Core\Subscriptions\Worker\SubscriptionRunners;

final class SubscriptionRunnersTest extends TestCase
{
    private float $now = 1000.0;

    public function test_overrides_win_over_the_default_and_star_replaces_the_default(): void
    {
        $runners = $this->runners();
        $this->assertSame(Runner::Queue, $runners->runner('orders'));
        $this->assertTrue($runners->queueRunsAny());

        $runners->set('orders', Runner::Worker);
        $this->assertTrue($runners->runsOnWorker('orders'));
        $this->assertFalse($runners->runsOnWorker('mails'));

        $runners->set(SubscriptionRunners::DEFAULT_KEY, Runner::Worker);
        $runners->set('mails', Runner::Queue);
        $this->assertTrue($runners->runsOnWorker('anything'));
        $this->assertFalse($runners->runsOnWorker('mails'));
        $this->assertTrue($runners->queueRunsAny());

        $runners->set('mails', null);
        $this->assertFalse($runners->queueRunsAny());
    }

    public function test_reads_are_cached_so_a_persist_does_not_hit_the_store_each_time(): void
    {
        $store = new InMemoryWorkerCoordination();
        $runners = $this->runners($store);
        $this->assertFalse($runners->runsOnWorker('orders'));

        // Another process switches it.
        $store->setRunnerOverride('orders', 'worker');
        $this->assertFalse($runners->runsOnWorker('orders'));

        $this->now += 2.1;
        $this->assertTrue($runners->runsOnWorker('orders'));
    }

    public function test_when_disabled_everything_runs_on_the_queue_and_the_store_is_never_read(): void
    {
        $store = $this->createMock(\Saucy\Core\Subscriptions\Worker\WorkerCoordination::class);
        $store->expects($this->never())->method('runnerOverrides');
        $runners = new SubscriptionRunners($store, Runner::Worker, enabled: false);

        $this->assertFalse($runners->runsOnWorker('orders'));
        $this->assertTrue($runners->queueRunsAny());
    }

    public function test_with_overrides_off_only_the_default_applies_and_the_store_is_never_read(): void
    {
        $store = $this->createMock(\Saucy\Core\Subscriptions\Worker\WorkerCoordination::class);
        $store->expects($this->never())->method('runnerOverrides');
        $runners = new SubscriptionRunners($store, Runner::Worker, readOverrides: false);

        $this->assertTrue($runners->runsOnWorker('orders'));
        $this->assertFalse($runners->queueRunsAny());
        $this->expectException(\LogicException::class);
        $runners->set('orders', Runner::Queue);
    }

    private function runners(?InMemoryWorkerCoordination $store = null): SubscriptionRunners
    {
        return new SubscriptionRunners($store ?? new InMemoryWorkerCoordination(), Runner::Queue, 2.0, fn() => $this->now);
    }
}
