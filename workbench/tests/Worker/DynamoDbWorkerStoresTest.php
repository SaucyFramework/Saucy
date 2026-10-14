<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\DynamoDbWorkerCoordination;
use Saucy\Core\Subscriptions\Worker\WorkerInfo;
use Aws\DynamoDb\DynamoDbClient;
use Saucy\Core\Subscriptions\Infra\DynamoDbRunningProcesses;
use Symfony\Component\Uid\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * the worker's DynamoDB stores, against DynamoDB Local, in a table shaped like Saucy's
 * processes table so the locks interoperate with DynamoDbRunningProcesses.
 */
final class DynamoDbWorkerStoresTest extends TestCase
{
    private DynamoDbClient $client;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('SAUCY_TEST_DYNAMODB_ENDPOINT');
        if (!is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('Set SAUCY_TEST_DYNAMODB_ENDPOINT (e.g. DynamoDB Local) to run the DynamoDB store tests.');
        }
        $this->client = new DynamoDbClient([
            'region' => 'eu-central-1',
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => ['key' => 'local', 'secret' => 'local'],
        ]);
        $this->table = 'fst6_worker_test_' . bin2hex(random_bytes(4));
        $this->client->createTable([
            'TableName' => $this->table,
            'AttributeDefinitions' => [['AttributeName' => 'pk', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'pk', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->table)) {
            $this->client->deleteTable(['TableName' => $this->table]);
        }
        parent::tearDown();
    }

    public function test_a_lease_renews_only_for_the_process_that_holds_it(): void
    {
        $processes = new DynamoDbRunningProcesses($this->client, $this->table);
        $renewer = $processes;
        $processId = (string) new Ulid();
        $processes->start('sells', $processId, new \DateTime('+30 seconds'));

        $this->assertTrue($renewer->renew('sells', $processId, new \DateTime('+90 seconds'), ['worker_sha' => 'abc123']));
        $this->assertGreaterThan(60, $processes->timeLeft($processId));
        $item = $this->client->getItem(['TableName' => $this->table, 'Key' => ['pk' => ['S' => 'PROCESS#sells']]])['Item'];
        $this->assertSame('abc123', $item['worker_sha']['S']);

        $this->assertFalse($renewer->renew('sells', (string) new Ulid(), new \DateTime('+90 seconds')));

        $processes->stop($processId);
        $this->assertFalse($renewer->renew('sells', $processId, new \DateTime('+90 seconds')));
        $this->assertFalse($processes->isActive('sells'));
    }

    public function test_an_expired_lock_is_taken_over_and_its_old_holder_cannot_delete_the_new_one(): void
    {
        $processes = new DynamoDbRunningProcesses($this->client, $this->table);
        $renewer = $processes;
        $dead = (string) new Ulid();
        $processes->start('sells', $dead, new \DateTime('-10 seconds'));

        // Not expired long enough yet.
        $this->assertFalse($renewer->takeOverExpired('sells', new \DateTime('-20 seconds')));
        $this->assertTrue($renewer->takeOverExpired('sells', new \DateTime('-5 seconds')));

        $next = (string) new Ulid();
        $processes->start('sells', $next, new \DateTime('+30 seconds'));
        // The dead process's stop() resolves nothing any more, so the new lock survives it.
        $processes->stop($dead);
        $this->assertTrue($processes->isActive('sells', $next));

        $this->assertFalse($renewer->takeOverExpired('sells', new \DateTime('-5 seconds')));
    }

    public function test_workers_see_each_other_until_they_leave_or_expire(): void
    {
        $coordination = new DynamoDbWorkerCoordination($this->client, $this->table);
        $now = time();

        $coordination->heartbeat(new WorkerInfo('w1', 'abc', $now + 20));
        $coordination->heartbeat(new WorkerInfo('w2', null, $now + 20));
        $coordination->heartbeat(new WorkerInfo('w3', 'abc', $now - 1));

        $live = $coordination->liveWorkers($now);
        $this->assertEqualsCanonicalizing(['w1', 'w2'], array_map(fn(WorkerInfo $worker) => $worker->id, $live));
        $this->assertSame([null], array_values(array_map(fn(WorkerInfo $w) => $w->sha, array_filter($live, fn(WorkerInfo $w) => $w->id === 'w2'))));

        $coordination->leave('w1');
        $this->assertSame(['w2'], array_map(fn(WorkerInfo $worker) => $worker->id, $coordination->liveWorkers($now)));

        // An entry dead for more than five minutes is pruned by whoever reads.
        $coordination->liveWorkers($now + 400);
        $item = $this->client->getItem(['TableName' => $this->table, 'Key' => ['pk' => ['S' => 'WORKER#MEMBERS']]])['Item'];
        $this->assertSame([], array_keys($item['workers']['M']));
    }

    public function test_the_deploy_gate_is_lifted_once_by_a_worker_on_the_deployed_commit(): void
    {
        $coordination = new DynamoDbWorkerCoordination($this->client, $this->table);
        $this->assertNull($coordination->gate());

        $coordination->pauseForDeploy('new', 1234);
        $this->assertTrue($coordination->gate()->paused);
        $this->assertSame(1234, $coordination->gate()->pausedAt);
        $this->assertFalse($coordination->gate()->admits('old'));

        $this->assertFalse($coordination->liftPause('old'));
        $this->assertTrue($coordination->liftPause('new'));
        $this->assertFalse($coordination->liftPause('new'));
        $this->assertFalse($coordination->gate()->paused);
        $this->assertSame('new', $coordination->gate()->sha);

        $coordination->clearGate();
        $this->assertNull($coordination->gate());
    }

    public function test_runner_overrides(): void
    {
        $coordination = new DynamoDbWorkerCoordination($this->client, $this->table);
        $this->assertSame([], $coordination->runnerOverrides());
        $coordination->setRunnerOverride('orders', null);

        $coordination->setRunnerOverride('orders', 'worker');
        $coordination->setRunnerOverride('*', 'worker');
        $coordination->setRunnerOverride('orders', 'queue');
        $this->assertEqualsCanonicalizing(['orders' => 'queue', '*' => 'worker'], $coordination->runnerOverrides());

        $coordination->setRunnerOverride('orders', null);
        $this->assertSame(['*' => 'worker'], $coordination->runnerOverrides());
    }

    public function test_a_late_stop_from_an_expired_holder_does_not_delete_the_next_lock(): void
    {
        $processes = new DynamoDbRunningProcesses($this->client, $this->table);
        $new = (string) new Ulid();
        $processes->start('sells', $new, new \DateTime('+30 seconds'));

        // A lookup entry left behind by the expired holder still points at the subscription.
        $old = (string) new Ulid();
        $this->client->putItem(['TableName' => $this->table, 'Item' => [
            'pk' => ['S' => "PROCESSID#{$old}"],
            'subscription_id' => ['S' => 'sells'],
            'process_id' => ['S' => $old],
        ]]);
        $processes->stop($old);

        $this->assertTrue($processes->isActive('sells', $new));
    }

    public function test_the_dashboard_listing_ignores_worker_items(): void
    {
        $coordination = new DynamoDbWorkerCoordination($this->client, $this->table);
        $coordination->heartbeat(new WorkerInfo('w1', 'abc', time() + 20));
        $coordination->pauseForDeploy('abc');

        $this->assertSame([], (new DynamoDbRunningProcesses($this->client, $this->table))->all());
    }
}
