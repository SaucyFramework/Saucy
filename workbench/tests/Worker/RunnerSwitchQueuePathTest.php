<?php

namespace Workbench\Tests\Worker;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamPollSubscriptionJob;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\IlluminateCheckpointStore;
use Saucy\Core\Subscriptions\Infra\DeferredTriggerSubscriptionProcessesAfterPersist;
use Saucy\Core\Subscriptions\Infra\IlluminateRunningProcesses;
use Saucy\Core\Subscriptions\Infra\TriggerSubscriptionProcessesAfterPersist;
use Saucy\Core\Subscriptions\Infra\TriggerSubscriptionsJob;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Worker\InMemoryWorkerCoordination;
use Saucy\Core\Subscriptions\Worker\Runner;
use Saucy\Core\Subscriptions\Worker\SubscriptionRunners;
use Saucy\MessageStorage\IlluminateMessageStorage;
use Saucy\MessageStorage\Serialization\EventSerializer;

/**
 * The queue side of `saucy:runner`: a subscription run by the worker gets no queue job, unless the
 * request needs it in sync.
 */
final class RunnerSwitchQueuePathTest extends WorkerTestCase
{
    use WritesEvents;

    private RunAllSubscriptionsInSync $runSync;

    private SubscriptionRunners $runners;

    private AllStreamSubscriptionRegistry $registry;

    private RunnerSwitchConsumer $onWorker;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->startAboveTheHead();
        $this->runSync = new RunAllSubscriptionsInSync(false);
        $this->runners = new SubscriptionRunners(new InMemoryWorkerCoordination(), Runner::Queue, 0.0);
        $this->onWorker = new RunnerSwitchConsumer();
        $this->registry = new AllStreamSubscriptionRegistry(
            $this->subscription('on_worker', $this->onWorker),
            $this->subscription('on_queue', new RunnerSwitchOtherConsumer()),
        );
        $this->runners->set('on_worker', Runner::Worker);
    }

    public function test_only_queue_run_subscriptions_get_a_poll_job(): void
    {
        $this->manager()->startProcessesThatRequireEvents(['runner_test_event']);

        Queue::assertPushed(AllStreamPollSubscriptionJob::class, 1);
        Queue::assertPushed(AllStreamPollSubscriptionJob::class, fn(AllStreamPollSubscriptionJob $job) => $job->subscriptionId === 'on_queue');
    }

    public function test_a_worker_run_subscription_still_runs_inline_when_the_request_awaits_it(): void
    {
        $position = $this->event(1, 'runner_test_event');
        $this->runSync->runSubscriptionInSync(RunnerSwitchConsumer::class);

        $this->manager()->startProcessesThatRequireEvents(['runner_test_event']);

        $this->assertSame([$position], $this->onWorker->handled);
    }

    public function test_no_trigger_job_is_queued_when_nothing_runs_on_the_queue(): void
    {
        $hook = $this->deferredHook();
        $event = new StreamEvent('e1', new \stdClass(), [], 1);

        $hook->trigger(new AggregateStreamName('runner_test', 'x'), $event);
        Queue::assertPushed(TriggerSubscriptionsJob::class, 1);

        $this->runners->set(SubscriptionRunners::DEFAULT_KEY, Runner::Worker);
        $hook->trigger(new AggregateStreamName('runner_test', 'x'), $event);
        Queue::assertPushed(TriggerSubscriptionsJob::class, 1);
    }

    private function manager(): AllStreamSubscriptionProcessManager
    {
        return new AllStreamSubscriptionProcessManager($this->registry, new IlluminateRunningProcesses(DB::connection()), $this->runSync, null, $this->runners);
    }

    private function deferredHook(): DeferredTriggerSubscriptionProcessesAfterPersist
    {
        $typeMap = new TypeMap([\stdClass::class => 'runner_test_event']);
        $streams = new StreamSubscriptionProcessManager(new StreamSubscriptionRegistry(), new IlluminateRunningProcesses(DB::connection()), $this->runSync, null, $this->runners);

        return new DeferredTriggerSubscriptionProcessesAfterPersist(
            $typeMap,
            $this->runSync,
            new TriggerSubscriptionProcessesAfterPersist($this->manager(), $streams, $typeMap),
            null,
            $this->runners,
        );
    }

    private function subscription(string $id, MessageConsumer $consumer): AllStreamSubscription
    {
        $serializer = $this->createStub(EventSerializer::class);
        $serializer->method('deserialize')->willReturn(new \stdClass());
        $streamNames = new TypeMap([AggregateStreamName::class => 'aggregate_stream_name']);

        return new AllStreamSubscription(
            subscriptionId: $id,
            streamOptions: new StreamOptions(eventTypes: ['runner_test_event'], startingFromPosition: $this->base),
            messageConsumer: $consumer,
            eventReader: new IlluminateMessageStorage(DB::connection(), $serializer, $streamNames),
            eventSerializer: $serializer,
            checkpointStore: new IlluminateCheckpointStore(DB::connection()),
            streamNameTypeMap: $streamNames,
            activityStreamLogger: new NoOpLogger(),
        );
    }
}

class RunnerSwitchConsumer implements MessageConsumer
{
    /** @var list<int> */
    public array $handled = [];

    public function handle(MessageConsumeContext $context): void
    {
        $this->handled[] = $context->globalPosition;
    }

    public static function getMessages(): array
    {
        return [];
    }
}

final class RunnerSwitchOtherConsumer extends RunnerSwitchConsumer {}
