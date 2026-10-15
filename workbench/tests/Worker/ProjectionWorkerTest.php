<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\HeadWatcher;
use Saucy\Core\Subscriptions\Worker\InMemoryWorkerCoordination;
use Saucy\Core\Subscriptions\Worker\ProjectionWorker;
use Saucy\Core\Subscriptions\Worker\Runner;
use Saucy\Core\Subscriptions\Worker\SubscriptionRunners;
use Saucy\Core\Subscriptions\Worker\EmbeddedMetricFormatMetrics;
use Saucy\Core\Subscriptions\Worker\WorkerMetrics;
use Saucy\Core\Subscriptions\Worker\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\IlluminateCheckpointStore;
use Saucy\Core\Subscriptions\Infra\IlluminateRunningProcesses;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscription;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Symfony\Component\Uid\Ulid;

/**
 * the projection worker against the real event store, checkpoint store and lock table.
 */
final class ProjectionWorkerTest extends WorkerTestCase
{
    use WritesEvents;

    private float $now;

    private InMemoryWorkerCoordination $coordination;

    private \Saucy\Core\Subscriptions\Infra\RunningProcesses&\Saucy\Core\Subscriptions\Infra\RunningProcessLeases $processes;

    private IlluminateCheckpointStore $checkpoints;

    /** @var array<string, list<int>> subscription id => global positions it handled, in order */
    private array $handled = [];

    /** @var array<string, true|float> subscriptions whose consumer throws (until a microtime, if a float) */
    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startAboveTheHead();
        // Real time: IlluminateRunningProcesses compares lock expiry with the wall clock.
        $this->now = microtime(true);
        $this->coordination = new InMemoryWorkerCoordination();
        $this->processes = new IlluminateRunningProcesses(DB::connection());
        $this->checkpoints = new IlluminateCheckpointStore(DB::connection());
    }

    public function test_it_polls_only_the_subscriptions_that_consume_the_new_events(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold']), $this->subscription('scans', ['ticket_scanned'])]);
        $worker->boot();
        $worker->tick();

        $position = $this->event(1, 'ticket_sold');
        $this->assertTrue($worker->tick());

        $this->assertSame([$position], $this->handled['sells'] ?? []);
        $this->assertSame([], $this->handled['scans'] ?? []);
        $this->assertSame($position, $this->checkpoints->get('sells')->position);
    }

    public function test_it_does_not_read_past_a_hole_until_the_transaction_commits(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();

        $first = $this->event(1, 'ticket_sold');
        $third = $this->event(3, 'ticket_sold');
        $worker->tick();
        $this->assertSame([$first], $this->handled['sells']);
        $this->assertSame($first, $this->checkpoints->get('sells')->position);

        $second = $this->event(2, 'ticket_sold');
        $this->now += 0.2;
        $worker->tick();

        $this->assertSame([$first, $second, $third], $this->handled['sells']);
    }

    public function test_a_checkpoint_that_is_already_past_the_hole_is_not_moved_back(): void
    {
        // A queue job (or an inline awaitProjection run) already handled everything up to the third
        // event while the worker still waits on the hole at the second.
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();
        $this->event(1, 'ticket_sold');
        $third = $this->event(3, 'ticket_sold');
        $this->checkpoints->store(new Checkpoint('sells', $third));

        $worker->tick();

        $this->assertSame([], $this->handled['sells'] ?? []);
        $this->assertSame($third, $this->checkpoints->get('sells')->position);
    }

    public function test_a_hole_that_never_fills_is_skipped_after_the_timeout(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();

        $first = $this->event(1, 'ticket_sold');
        $third = $this->event(3, 'ticket_sold');
        $worker->tick();
        $this->now += 6;
        $worker->tick();

        $this->assertSame([$first, $third], $this->handled['sells']);
    }

    public function test_two_workers_split_the_subscriptions_and_each_event_is_handled_once(): void
    {
        $ids = array_map(fn(int $i) => "projector_{$i}", range(1, 8));
        $subscriptions = fn() => array_map(fn(string $id) => $this->subscription($id, ['ticket_sold']), $ids);

        $one = $this->worker($subscriptions(), workerId: (string) new Ulid());
        $two = $this->worker($subscriptions(), workerId: (string) new Ulid());
        $one->boot();
        $two->boot();
        // The first worker saw only itself at boot; its next heartbeat sees both.
        $this->now += 5;
        $one->tick();
        $two->tick();

        $owned = [...$one->ownedSubscriptions(), ...$two->ownedSubscriptions()];
        sort($owned);
        $this->assertSame($ids, $owned);
        $this->assertNotSame([], $one->ownedSubscriptions());
        $this->assertNotSame([], $two->ownedSubscriptions());

        $position = $this->event(1, 'ticket_sold');
        $one->tick();
        $two->tick();

        foreach ($ids as $id) {
            $this->assertSame([$position], $this->handled[$id], $id);
        }
    }

    public function test_it_waits_while_a_queue_job_holds_the_lock(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();
        $worker->tick();

        $jobProcess = (string) new Ulid();
        $this->processes->start('sells', $jobProcess, new \DateTime('+5 minutes'));
        $position = $this->event(1, 'ticket_sold');
        $worker->tick();
        $this->assertSame([], $this->handled['sells'] ?? []);

        $this->processes->stop($jobProcess);
        $this->now += 1.1;
        $worker->tick();
        $this->assertSame([$position], $this->handled['sells']);
    }

    public function test_it_keeps_the_lock_while_busy_and_hands_it_back_when_idle(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();
        $this->event(1, 'ticket_sold');
        $worker->tick();
        $this->assertTrue($this->processes->isActive('sells'));

        $this->now += 16;
        $worker->tick();
        $this->assertFalse($this->processes->isActive('sells'));
    }

    public function test_a_worker_on_an_old_version_takes_no_locks_and_the_new_one_lifts_the_pause(): void
    {
        $old = $this->worker([$this->subscription('sells', ['ticket_sold'])], sha: 'old', workerId: (string) new Ulid());
        $old->boot();
        $this->coordination->pauseForDeploy('new');

        $position = $this->event(1, 'ticket_sold');
        $this->now += 1;
        $old->tick();
        $this->assertSame([], $old->ownedSubscriptions());
        $this->assertSame([], $this->handled['sells'] ?? []);

        $new = $this->worker([$this->subscription('sells', ['ticket_sold'])], sha: 'new', workerId: (string) new Ulid());
        $new->boot();
        $new->tick();

        $this->assertFalse($this->coordination->gate()->paused);
        $this->assertSame(['sells'], $new->ownedSubscriptions());
        $this->assertSame([$position], $this->handled['sells']);
    }

    public function test_a_failing_subscription_does_not_hold_up_the_others(): void
    {
        // Fails before any handler runs (the way a lost connection does), so Saucy's 60 s in-line
        // retry of a failing event does not apply.
        $this->failing['broken'] = true;
        $worker = $this->worker([$this->subscription('broken', ['ticket_sold'], batches: true), $this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();

        $position = $this->event(1, 'ticket_sold');
        $worker->tick();

        $this->assertSame([$position], $this->handled['sells']);
        $this->assertFalse($this->processes->isActive('broken'));

        unset($this->failing['broken']);
        $this->now += 2.1;
        $worker->tick();
        $this->assertSame([$position], $this->handled['broken']);
    }

    public function test_per_aggregate_projectors_are_polled_per_stream(): void
    {
        $worker = $this->worker([], [$this->streamSubscription('order_lines', 'order', ['ticket_sold'])]);
        $worker->boot();

        $first = $this->event(1, 'ticket_sold', 'order###1');
        $this->event(2, 'ticket_sold', 'order###2');
        $second = $this->event(3, 'ticket_sold', 'order###1', 2);
        $worker->tick();

        $this->assertCount(3, $this->handled['order_lines']);
        $this->assertSame(2, $this->checkpoints->get('order_lines_' . (new AggregateStreamName('order', '1'))->toString())->position);
        $this->assertLessThan(
            array_search($second, $this->handled['order_lines'], true),
            array_search($first, $this->handled['order_lines'], true),
        );
        $this->assertFalse($this->processes->isActive('order_lines_order###1'));
    }

    public function test_per_aggregate_triggers_recorded_during_a_deploy_pause_reach_the_new_version(): void
    {
        $old = $this->worker([], [$this->streamSubscription('order_lines', 'order', ['ticket_sold'])], sha: 'old', workerId: (string) new Ulid());
        $old->boot();
        $old->tick();

        $this->coordination->pauseForDeploy('new', $this->base);
        $this->now += 1;
        $old->tick();

        // Recorded while no worker may run; well beyond the 0-position boot lookback.
        $this->event(1, 'ticket_sold', 'order###1');
        $this->event(2, 'unrelated', 'other###1');
        $old->tick();
        $this->assertSame([], $this->handled['order_lines'] ?? []);

        // The new version starts ten minutes later: its 120 s lookback alone would not reach back.
        $this->now += 600;
        $new = $this->worker([], [$this->streamSubscription('order_lines', 'order', ['ticket_sold'])], sha: 'new', workerId: (string) new Ulid(), bootLookback: 1000, bootLookbackSeconds: 120.0);
        $new->boot();
        $new->tick();

        $this->assertSame([$this->base + 1], $this->handled['order_lines']);
    }

    public function test_a_per_aggregate_trigger_that_is_already_handled_takes_no_lock(): void
    {
        $processes = $this->createMockForIntersectionOfInterfaces([\Saucy\Core\Subscriptions\Infra\RunningProcesses::class, \Saucy\Core\Subscriptions\Infra\RunningProcessLeases::class]);
        $processes->expects($this->never())->method('start');
        $this->processes = $processes;
        $this->checkpoints->store(new Checkpoint('order_lines_order###1', 1));

        $worker = $this->worker([], [$this->streamSubscription('order_lines', 'order', ['ticket_sold'])]);
        $worker->boot();
        $this->event(1, 'ticket_sold', 'order###1');
        $worker->tick();

        $this->assertSame([], $this->handled['order_lines'] ?? []);
    }

    public function test_a_projection_left_on_the_queue_is_not_run_until_it_is_switched_to_the_worker(): void
    {
        $runners = new SubscriptionRunners($this->coordination, Runner::Queue, 0.0);
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])], runners: $runners);
        $worker->boot();
        $position = $this->event(1, 'ticket_sold');
        $worker->tick();
        $this->assertSame([], $worker->ownedSubscriptions());
        $this->assertSame([], $this->handled['sells'] ?? []);

        $runners->set('sells', Runner::Worker);
        $this->now += 1.1;
        $worker->tick();

        $this->assertSame(['sells'], $worker->ownedSubscriptions());
        $this->assertSame([$position], $this->handled['sells']);
    }

    public function test_a_projection_moved_back_to_the_queue_is_handed_over_after_a_grace_period(): void
    {
        $runners = new SubscriptionRunners($this->coordination, Runner::Worker, 0.0);
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])], runners: $runners);
        $worker->boot();
        $first = $this->event(1, 'ticket_sold');
        $worker->tick();

        $runners->set('sells', Runner::Queue);
        $this->now += 1.1;
        // Within the grace: processes still caching "worker" skip the queue trigger, so the worker
        // keeps handling it.
        $second = $this->event(2, 'ticket_sold');
        $worker->tick();
        $this->assertSame([$first, $second], $this->handled['sells']);
        $this->assertSame(['sells'], $worker->ownedSubscriptions());

        $this->now += 10;
        $worker->tick();
        $this->assertSame([], $worker->ownedSubscriptions());
        $this->assertFalse($this->processes->isActive('sells'));
    }

    public function test_a_skip_message_subscription_keeps_the_queue_retry_budget(): void
    {
        // Fails for ~1 s, then recovers: longer than the worker's budget, shorter than the queue's.
        $this->failing['flaky_skip'] = microtime(true) + 1.0;
        // Still failing when its turn comes: shows the short budget applies to Halt.
        $this->failing['flaky_halt'] = microtime(true) + 30.0;
        $worker = $this->worker([
            $this->subscription('flaky_skip', ['ticket_sold'], failureMode: FailureMode::SkipMessage, retryBudgetSeconds: 3.0),
            $this->subscription('flaky_halt', ['ticket_sold'], failureMode: FailureMode::Halt, retryBudgetSeconds: 3.0),
        ], retryBudgetSeconds: 0.3);
        $worker->boot();

        $position = $this->event(1, 'ticket_sold');
        $started = microtime(true);
        $worker->tick();
        $elapsed = microtime(true) - $started;

        // SkipMessage would have dropped the event for good after 0.3 s; with 3 s it got through.
        $this->assertSame([$position], $this->handled['flaky_skip']);
        // Halt gives up after 0.3 s (not the subscription's 3 s) and gets the event again on a later poll.
        $this->assertSame([], $this->handled['flaky_halt'] ?? []);
        // ~1 s for the SkipMessage recovery + ~0.3 s for Halt; with Halt on the 3 s budget this would be ≥ 4 s.
        $this->assertLessThan(2.5, $elapsed);
    }

    public function test_a_worker_held_by_the_deploy_gate_reports_it_as_lag(): void
    {
        $stream = fopen('php://memory', 'w+');
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])], sha: 'old', metrics: new EmbeddedMetricFormatMetrics($stream, 'testing'));
        $worker->boot();
        $this->coordination->pauseForDeploy('new');

        $this->now += 30;
        $worker->tick();

        rewind($stream);
        $lines = array_map(fn(string $line) => json_decode($line, true), array_filter(explode("\n", (string) stream_get_contents($stream))));
        $last = end($lines);
        $this->assertGreaterThanOrEqual(29, $last['WorkerGateHeldSeconds']);
        $this->assertGreaterThanOrEqual(29, $last['ProjectionLagSeconds']);
    }

    public function test_shutdown_hands_back_locks_and_leaves(): void
    {
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])]);
        $worker->boot();
        $this->event(1, 'ticket_sold');
        $worker->tick();
        $this->assertTrue($this->processes->isActive('sells'));

        $worker->shutdown();

        $this->assertFalse($this->processes->isActive('sells'));
        $this->assertSame([], $this->coordination->liveWorkers((int) $this->now));
    }

    public function test_it_reports_lag_as_embedded_metrics(): void
    {
        $stream = fopen('php://memory', 'w+');
        $worker = $this->worker([$this->subscription('sells', ['ticket_sold'])], metrics: new EmbeddedMetricFormatMetrics($stream, 'testing'));
        $worker->boot();
        $this->event(1, 'ticket_sold');
        $this->now += 10;
        $worker->tick();

        rewind($stream);
        $line = json_decode((string) fgets($stream), true);
        $this->assertSame('Saucy/Worker', $line['_aws']['CloudWatchMetrics'][0]['Namespace']);
        $this->assertSame(1, $line['EventsHandled']);
        $this->assertSame(0, $line['PendingSubscriptions']);
        $this->assertSame(1, $line['HeldLeases']);
    }

    /**
     * @param list<AllStreamSubscription> $subscriptions
     * @param list<StreamSubscription> $streamSubscriptions
     */
    private function worker(
        array $subscriptions,
        array $streamSubscriptions = [],
        ?string $sha = null,
        ?string $workerId = null,
        ?WorkerMetrics $metrics = null,
        int $bootLookback = 0,
        ?float $bootLookbackSeconds = null,
        ?SubscriptionRunners $runners = null,
        ?float $retryBudgetSeconds = 5.0,
    ): ProjectionWorker {
        return new ProjectionWorker(
            allStreamRegistry: new AllStreamSubscriptionRegistry(...$subscriptions),
            streamRegistry: new StreamSubscriptionRegistry(...$streamSubscriptions),
            runningProcesses: $this->processes,
            coordination: $this->coordination,
            headWatcher: new HeadWatcher(
                connection: DB::connection(),
                gapTimeoutSeconds: 5.0,
                bootLookback: $bootLookback,
                clock: fn() => $this->now,
                bootLookbackSeconds: $bootLookbackSeconds,
            ),
            options: new WorkerOptions(sha: $sha, retryBudgetSeconds: $retryBudgetSeconds),
            logger: new NullLogger(),
            metrics: $metrics,
            clock: fn() => $this->now,
            sleep: function (float $seconds) {
                $this->now += $seconds;
            },
            workerId: $workerId,
            runners: $runners,
        );
    }

    /**
     * @param list<string> $eventTypes
     */
    private function subscription(string $id, array $eventTypes, bool $batches = false, FailureMode $failureMode = FailureMode::Halt, float $retryBudgetSeconds = 60.0): AllStreamSubscription
    {
        return new AllStreamSubscription(
            subscriptionId: $id,
            streamOptions: new StreamOptions(eventTypes: $eventTypes),
            messageConsumer: $batches ? $this->batchConsumer($id) : $this->consumer($id),
            eventReader: $this->storage(),
            eventSerializer: $this->serializer(),
            checkpointStore: $this->checkpoints,
            streamNameTypeMap: $this->streamNameTypeMap(),
            activityStreamLogger: new NoOpLogger(),
            failureMode: $failureMode,
            retryBudgetSeconds: $retryBudgetSeconds,
        );
    }

    /**
     * @param list<string> $eventTypes
     */
    private function streamSubscription(string $id, string $aggregateType, array $eventTypes): StreamSubscription
    {
        return new StreamSubscription(
            subscriptionId: $id,
            aggregateType: $aggregateType,
            streamOptions: new StreamOptions(eventTypes: $eventTypes),
            messageConsumer: $this->consumer($id),
            eventReader: $this->storage(),
            eventSerializer: $this->serializer(),
            checkpointStore: $this->checkpoints,
            streamNameTypeMap: $this->streamNameTypeMap(),
        );
    }

    private function consumer(string $id): MessageConsumer
    {
        $record = function (MessageConsumeContext $context) use ($id) {
            $failing = $this->failing[$id] ?? null;
            if ($failing === true || (is_float($failing) && microtime(true) < $failing)) {
                throw new \RuntimeException("{$id} is broken");
            }
            $this->handled[$id][] = $context->globalPosition;
        };

        return new class ($record) implements MessageConsumer {
            public function __construct(private \Closure $record) {}

            public function handle(MessageConsumeContext $context): void
            {
                ($this->record)($context);
            }

            public static function getMessages(): array
            {
                return [];
            }
        };
    }

    private function batchConsumer(string $id): MessageConsumer
    {
        $record = function (MessageConsumeContext $context) use ($id) {
            $this->handled[$id][] = $context->globalPosition;
        };
        $fail = function () use ($id) {
            if (isset($this->failing[$id])) {
                throw new \RuntimeException("{$id} is broken");
            }
        };

        return new class ($record, $fail) implements MessageConsumer, MessageConsumerThatHandlesBatches {
            public function __construct(private \Closure $record, private \Closure $fail) {}

            public function handle(MessageConsumeContext $context): void
            {
                ($this->record)($context);
            }

            public static function getMessages(): array
            {
                return [];
            }

            public function beforeHandlingBatch(): void
            {
                ($this->fail)();
            }

            public function afterHandlingBatch(): void {}

            public function getBatchSize(): int
            {
                return 100;
            }
        };
    }

    private function storage(): IlluminateMessageStorage
    {
        return new IlluminateMessageStorage(DB::connection(), $this->serializer(), $this->streamNameTypeMap());
    }

    private function serializer(): EventSerializer
    {
        $serializer = $this->createStub(EventSerializer::class);
        $serializer->method('deserialize')->willReturnCallback(fn(SerializationResult $result) => new \stdClass());

        return $serializer;
    }

    private function streamNameTypeMap(): TypeMap
    {
        return new TypeMap([AggregateStreamName::class => 'aggregate_stream_name']);
    }
}
