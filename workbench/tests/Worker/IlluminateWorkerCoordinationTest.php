<?php

namespace Workbench\Tests\Worker;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Subscriptions\Worker\IlluminateWorkerCoordination;
use Saucy\Core\Subscriptions\Worker\WorkerInfo;

final class IlluminateWorkerCoordinationTest extends WorkerTestCase
{
    private IlluminateWorkerCoordination $coordination;

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__ . '/../../../migrations/worker/create_saucy_worker_state_table.php.stub')->up();
        $this->coordination = new IlluminateWorkerCoordination(DB::connection());
    }

    public function test_workers_see_each_other_until_they_leave_or_expire(): void
    {
        $now = time();
        $this->coordination->heartbeat(new WorkerInfo('w1', 'abc', $now + 20));
        $this->coordination->heartbeat(new WorkerInfo('w2', null, $now + 20));
        $this->coordination->heartbeat(new WorkerInfo('w3', 'abc', $now - 1));
        $this->coordination->heartbeat(new WorkerInfo('w1', 'abc', $now + 25));

        $this->assertEqualsCanonicalizing(['w1', 'w2'], array_map(fn(WorkerInfo $w) => $w->id, $this->coordination->liveWorkers($now)));

        $this->coordination->leave('w1');
        $this->assertSame(['w2'], array_map(fn(WorkerInfo $w) => $w->id, $this->coordination->liveWorkers($now)));
    }

    public function test_the_deploy_gate_is_lifted_once_by_a_worker_on_the_deployed_commit(): void
    {
        $this->assertNull($this->coordination->gate());
        $this->coordination->pauseForDeploy('new', 1234);
        $this->assertTrue($this->coordination->gate()->paused);
        $this->assertSame(1234, $this->coordination->gate()->pausedAt);

        $this->assertFalse($this->coordination->liftPause('old'));
        $this->assertTrue($this->coordination->liftPause('new'));
        $this->assertFalse($this->coordination->liftPause('new'));
        $this->assertSame(1234, $this->coordination->gate()->pausedAt);

        $this->coordination->clearGate();
        $this->assertNull($this->coordination->gate());
    }

    public function test_runner_overrides(): void
    {
        $this->coordination->setRunnerOverride('orders', 'worker');
        $this->coordination->setRunnerOverride('*', 'queue');
        $this->coordination->setRunnerOverride('orders', 'queue');
        $this->assertEquals(['orders' => 'queue', '*' => 'queue'], $this->coordination->runnerOverrides());

        $this->coordination->setRunnerOverride('orders', null);
        $this->assertSame(['*' => 'queue'], $this->coordination->runnerOverrides());
    }
}
