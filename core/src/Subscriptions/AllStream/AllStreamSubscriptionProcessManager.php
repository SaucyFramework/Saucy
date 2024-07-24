<?php

namespace Saucy\Core\Subscriptions\AllStream;

use DateInterval;
use DateTime;
use EventSauce\BackOff\BackOffRunner;
use EventSauce\BackOff\LinearBackOffStrategy;
use EventSauce\EventSourcing\UnableToPersistMessages;
use Illuminate\Support\Facades\Log;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Symfony\Component\Uid\Ulid;

final readonly class AllStreamSubscriptionProcessManager
{
    private DateInterval $defaultProcessTimeout;

    public function __construct(
        private AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
        private RunningProcesses $runningProcesses,
        private RunAllSubscriptionsInSync $runSync,
        ?DateInterval $defaultProcessTimeout = null,
    ) {
        $this->defaultProcessTimeout = $defaultProcessTimeout ?? new DateInterval('PT5M');
    }


    /**
     * Can be used to trigger all projections based on a cron schedule
     * @return void
     */
    public function startProcesses(): void
    {
        // start all streams as processes
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            $this->startStreamIfNotRunning($stream);
        }
    }

    /**
     * @param array<string> $eventTypes
     * @return void
     */
    public function startProcessesThatRequireEvents(array $eventTypes): void
    {
        // start all streams as processes
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            if($stream->streamOptions->eventTypes === null) {
                continue;
            }
            if(count(array_intersect($stream->streamOptions->eventTypes, $eventTypes)) === 0) {
                continue;
            }
            $this->startStreamIfNotRunning($stream);
        }
    }

    public function startProcess(string $name): void
    {
        $this->startStreamIfNotRunning($this->allStreamSubscriptionRegistry->get($name));
    }

    public function pause(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $this->runningProcesses->pause($stream->subscriptionId, 'paused');
    }

    public function resume(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $this->runningProcesses->resume($stream->subscriptionId);
    }

    public function replaySubscription(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        // pause other triggers of this process
        $this->runningProcesses->pause($stream->subscriptionId, 'paused for replay');

        // wait to obtain lock
        $processId = Ulid::generate();
        $runner = new BackOffRunner(new LinearBackOffStrategy(500, 100), StartProcessException::class);
        $runner->run(function () use ($stream, $processId) {
            $this->runningProcesses->start(
                subscriptionId: $stream->subscriptionId,
                processId: $processId,
                expiresAt: (new DateTime('now'))->add(new DateInterval("PT15M")),
                ignorePaused: true,
            );
        });

        $stream->prepareForReplay();
        $this->runningProcesses->resume($stream->subscriptionId);
        $this->runningProcesses->stop($processId);

        $this->startStreamIfNotRunning($stream);
    }

    private function startStreamIfNotRunning(AllStreamSubscription $stream): void
    {
        if($this->runningProcesses->isActive($stream->subscriptionId)) {
            return;
        }

        $processId = Ulid::generate();
        try {
            $this->runningProcesses->start(
                $stream->subscriptionId,
                $processId,
                (new DateTime('now'))->add($stream->streamOptions->processTimeoutInSeconds !== null ? new DateInterval("PT{$stream->streamOptions->processTimeoutInSeconds}S") : $this->defaultProcessTimeout)
            );
        } catch (StartProcessException $exception) {
            // process already running, stop execution
            return;
        }

        if(!$this->runSync->isRunSync()) {
            AllStreamPollSubscriptionJob::dispatch($stream->subscriptionId, $processId)->onQueue($stream->streamOptions->queue);
            return;
        }

        $subscription = $this->allStreamSubscriptionRegistry->get($stream->subscriptionId);
        try {
            $subscription->poll();
        } finally {
            $this->runningProcesses->stop($processId);
        }
    }

}
