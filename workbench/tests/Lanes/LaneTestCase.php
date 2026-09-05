<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes;

use Illuminate\Support\Facades\DB;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Lanes\InMemoryLaneCoordinator;
use Saucy\Core\Subscriptions\Lanes\LaneConfig;
use Saucy\Core\Subscriptions\Lanes\LaneCoordinator;
use Saucy\Core\Subscriptions\Lanes\LaneProcessManager;
use Saucy\Core\Subscriptions\Lanes\LaneRegistry;
use Saucy\Core\Subscriptions\Lanes\LaneRunner;
use Saucy\Core\Subscriptions\Lanes\LaneRunnerFactory;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\Core\Subscriptions\PoisonMessages\RetryPolicy;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Saucy\Core\Subscriptions\StreamOptions;
use Saucy\MessageStorage\AllStreamReader;
use Workbench\Tests\Lanes\Fixtures\CountingAllStreamReader;
use Workbench\Tests\Lanes\Fixtures\PassThroughSerializer;
use Workbench\Tests\Lanes\Fixtures\RecordingCheckpointStore;
use Workbench\Tests\WithDatabaseTestCase;

/**
 * Shared scaffolding for the projection-lane tests: raw `event_store` rows plus hand-built
 * AllStreamSubscription instances, so the lane can be exercised without the container's
 * projector discovery.
 */
abstract class LaneTestCase extends WithDatabaseTestCase
{
    protected RecordingCheckpointStore $checkpoints;
    protected InMemoryLaneCoordinator $coordinator;
    protected CountingAllStreamReader $reader;

    /** @var array<int, string> member ids passed to the catch-up callable */
    protected array $catchUpCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkpoints = new RecordingCheckpointStore();
        $this->coordinator = new InMemoryLaneCoordinator();
        $this->reader = new CountingAllStreamReader($this->app->make(AllStreamReader::class));
        $this->catchUpCalls = [];
    }

    protected function typeMap(): TypeMap
    {
        return new TypeMap([AggregateStreamName::class => 'test_stream']);
    }

    /**
     * Inserts one event and returns its global position.
     */
    protected function insertEvent(string $eventType, string $streamName = 'test###one'): int
    {
        $position = (int) (DB::table('event_store')->max('global_position') ?? 0) + 1;

        DB::table('event_store')->insert([
            'global_position' => $position,
            'message_id' => str_pad((string) $position, 26, '0', STR_PAD_LEFT),
            'message_type' => $eventType,
            'stream_name_type' => 'test_stream',
            'stream_type' => 'test',
            'stream_name' => $streamName,
            'stream_position' => $position,
            'payload' => json_encode(['n' => $position]),
            'metadata' => json_encode([]),
            'created_at' => '2026-01-01 00:00:00',
        ]);

        return $position;
    }

    /**
     * @param array<string>|null $eventTypes
     */
    protected function member(
        string $subscriptionId,
        MessageConsumer $consumer,
        ?array $eventTypes,
        FailureMode $failureMode = FailureMode::Halt,
        int $startingFromPosition = 0,
    ): AllStreamSubscription {
        return new AllStreamSubscription(
            subscriptionId: $subscriptionId,
            streamOptions: new StreamOptions(
                eventTypes: $eventTypes,
                startingFromPosition: $startingFromPosition,
            ),
            messageConsumer: $consumer,
            eventReader: $this->reader,
            eventSerializer: new PassThroughSerializer(),
            checkpointStore: $this->checkpoints,
            streamNameTypeMap: $this->typeMap(),
            activityStreamLogger: new NoOpLogger(),
            failureMode: $failureMode,
            poisonMessageStore: $this->app->make(PoisonMessageStore::class),
            poisonMessageRecorder: $this->app->make(PoisonMessageRecorder::class),
        );
    }

    /**
     * @param array<string, AllStreamSubscription> $members
     */
    protected function runner(
        array $members,
        ?LaneConfig $config = null,
        ?LaneCoordinator $coordinator = null,
        ?callable $startCatchUp = null,
        ?\Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger $activityStreamLogger = null,
    ): LaneRunner {
        return new LaneRunner(
            config: $config ?? new LaneConfig(name: 'default', pageSize: 100),
            members: $members,
            eventReader: $this->reader,
            eventSerializer: new PassThroughSerializer(),
            streamNameTypeMap: $this->typeMap(),
            runningProcesses: $this->app->make(RunningProcesses::class),
            poisonMessageStore: $this->app->make(PoisonMessageStore::class),
            poisonMessageRecorder: $this->app->make(PoisonMessageRecorder::class),
            activityStreamLogger: $activityStreamLogger ?? new NoOpLogger(),
            coordinator: $coordinator ?? $this->coordinator,
            startCatchUp: $startCatchUp ?? function (string $memberId): void {
                $this->catchUpCalls[] = $memberId;
            },
            // A tiny budget keeps the failing-handler tests well under a second.
            retryPolicy: new RetryPolicy(initialDelayMs: 1, multiplier: 2, maxTotalSeconds: 0),
        );
    }

    /**
     * @param array<string, AllStreamSubscription> $members
     */
    protected function subscriptionRegistry(array $members): AllStreamSubscriptionRegistry
    {
        return new AllStreamSubscriptionRegistry(...array_values($members));
    }

    /**
     * @param array<string, AllStreamSubscription> $members
     * @param array<string, LaneConfig> $lanes empty = lanes disabled
     * @param array<string, string> $assignments
     */
    protected function laneRegistry(
        array $members,
        array $lanes = [],
        array $assignments = [],
    ): LaneRegistry {
        return new LaneRegistry(
            $lanes,
            $assignments,
            [],
            $this->subscriptionRegistry($members),
        );
    }

    protected function laneProcessManager(
        LaneRegistry $laneRegistry,
        ?LaneCoordinator $coordinator = null,
        float $syncClaimTimeoutInSeconds = 2.0,
    ): LaneProcessManager {
        return new LaneProcessManager(
            $laneRegistry,
            $this->app->make(RunningProcesses::class),
            $coordinator ?? $this->coordinator,
            $syncClaimTimeoutInSeconds,
        );
    }

    /**
     * Takes the lane's own lease, standing in for a lane process running in another worker.
     */
    protected function holdLaneLease(string $lane = 'default'): string
    {
        $processId = \Symfony\Component\Uid\Ulid::generate();

        $this->app->make(RunningProcesses::class)->start(
            subscriptionId: 'lane__' . $lane,
            processId: $processId,
            expiresAt: (new \DateTime('now'))->modify('+5 minutes'),
        );

        return $processId;
    }

    /**
     * @param array<string, AllStreamSubscription> $members
     */
    protected function allStreamProcessManager(
        array $members,
        LaneRegistry $laneRegistry,
        ?LaneProcessManager $laneProcessManager = null,
        bool $runSync = false,
    ): AllStreamSubscriptionProcessManager {
        return new AllStreamSubscriptionProcessManager(
            $this->subscriptionRegistry($members),
            $this->app->make(RunningProcesses::class),
            // The testing environment sets runSync = true globally; lane routing needs the
            // async behaviour a production app has.
            new RunAllSubscriptionsInSync($runSync),
            null,
            $laneRegistry,
            $laneProcessManager ?? $this->laneProcessManager($laneRegistry),
        );
    }

    protected function laneRunnerFactory(
        LaneRegistry $laneRegistry,
        AllStreamSubscriptionProcessManager $processManager,
        ?LaneCoordinator $coordinator = null,
    ): LaneRunnerFactory {
        return new LaneRunnerFactory(
            $laneRegistry,
            $this->reader,
            new PassThroughSerializer(),
            $this->typeMap(),
            $this->app->make(RunningProcesses::class),
            $this->app->make(PoisonMessageStore::class),
            $this->app->make(PoisonMessageRecorder::class),
            new NoOpLogger(),
            $coordinator ?? $this->coordinator,
            $processManager,
        );
    }

    protected function setCheckpoint(string $subscriptionId, int $position): void
    {
        $this->checkpoints->store(new Checkpoint($subscriptionId, $position));
        $this->checkpoints->writes = [];
    }
}
