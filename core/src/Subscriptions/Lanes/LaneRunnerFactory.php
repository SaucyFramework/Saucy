<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageRecorder;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStore;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;

final readonly class LaneRunnerFactory
{
    public function __construct(
        private LaneRegistry $laneRegistry,
        private AllStreamReader $eventReader,
        private EventSerializer $eventSerializer,
        private TypeMap $typeMap,
        private RunningProcesses $runningProcesses,
        private PoisonMessageStore $poisonMessageStore,
        private PoisonMessageRecorder $poisonMessageRecorder,
        private ActivityStreamLogger $activityStreamLogger,
        private LaneCoordinator $coordinator,
        private AllStreamSubscriptionProcessManager $processManager,
    ) {}

    public function make(string $lane): LaneRunner
    {
        return new LaneRunner(
            config: $this->laneRegistry->lane($lane),
            members: $this->laneRegistry->members($lane),
            eventReader: $this->eventReader,
            eventSerializer: $this->eventSerializer,
            streamNameTypeMap: $this->typeMap,
            runningProcesses: $this->runningProcesses,
            poisonMessageStore: $this->poisonMessageStore,
            poisonMessageRecorder: $this->poisonMessageRecorder,
            activityStreamLogger: $this->activityStreamLogger,
            coordinator: $this->coordinator,
            // A callable rather than the process manager itself, so that
            // AllStreamSubscriptionProcessManager -> LaneProcessManager -> LaneRunner stays acyclic.
            startCatchUp: fn(string $memberId) => $this->processManager->startStandalone($memberId),
        );
    }
}
