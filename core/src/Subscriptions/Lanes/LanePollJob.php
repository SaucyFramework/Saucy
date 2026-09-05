<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use DateInterval;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Symfony\Component\Uid\Ulid;

/**
 * The long-lived poller for one lane. Modelled on AllStreamPollSubscriptionJob, but it LOOPS
 * instead of recursing: with a 240 s lease and 250 ms sleeps the recursive shape would build up
 * roughly a thousand stack frames.
 *
 * The job holds the lane's lease for its whole run, so it must never be retried or redelivered
 * while it is still running: two live copies would both pass `isActive($laneId, $processId)` and
 * two runners would fan out the same events. `tries = 1` and a `timeout` set just past the lease
 * (see {@see LaneProcessManager::dispatchPollJob()}) are the guard on the worker side; the queue
 * connection's `retry_after` / SQS visibility timeout must exceed it on the broker side.
 */
final class LanePollJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Never retry: a retry would run a second poller against a lease this job still holds. */
    public int $tries = 1;

    /** Set at dispatch time to the lane's process timeout plus a grace. */
    public ?int $timeout = null;

    /**
     * The lane job self-chains this long before its lease expires. It is deliberately larger
     * than the 5 s the legacy per-subscription job uses: everything that waits on
     * `isActive($laneId)` (a sync claim, an operator quiesce) treats an inactive lease as "no
     * runner can be mid-page", so a slow handler needs real headroom before another process is
     * allowed to draw that conclusion.
     */
    private const int LEASE_HEADROOM_SECONDS = 30;

    /** How often the remote lease is re-checked while the loop is running. */
    private const int LEASE_RECHECK_SECONDS = 5;

    public function __construct(
        public string $laneName,
        public string $processId,
    ) {}

    public function handle(
        LaneRegistry $laneRegistry,
        RunningProcesses $runningProcesses,
        LaneRunnerFactory $laneRunnerFactory,
    ): void {
        try {
            // Resolved inside the try so that a missing/renamed lane still releases the lease
            // instead of leaving it to expire.
            $config = $laneRegistry->lane($this->laneName);
            $laneId = $laneRegistry->laneSubscriptionId($this->laneName);

            $this->runLane($config, $laneId, $laneRunnerFactory->make($this->laneName), $runningProcesses);
        } catch (\Throwable $e) {
            $runningProcesses->stop($this->processId);
            throw $e;
        }
    }

    public function displayName(): string
    {
        return "projection lane: {$this->laneName}";
    }

    /**
     * @return array<int|string, string>
     */
    public function tags(): array
    {
        return [
            'projection-lane',
            'lane:' . $this->laneName,
            'processId' => $this->processId,
        ];
    }

    /**
     * Per loop iteration this costs: one coordinator read, one `maxEventId()`, one `paginate()`
     * and the checkpoint writes for members that moved. The lease is a REMOTE read, so it is
     * taken once up front and then re-checked at most every LEASE_RECHECK_SECONDS; the rest of
     * the time the remaining budget is computed locally from the expiry captured at the start.
     */
    private function runLane(
        LaneConfig $config,
        string $laneId,
        LaneRunner $runner,
        RunningProcesses $runningProcesses,
    ): void {
        if (!$runningProcesses->isActive($laneId, $this->processId)) {
            $runningProcesses->reportStatus($this->processId, 'stopping');
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($config, $laneId, $runningProcesses);
            return;
        }

        $expiresAt = time() + $runningProcesses->timeLeft($this->processId);
        $leaseCheckedAt = time();

        // Reported once for the process, not once per loop iteration.
        $runningProcesses->reportStatus($this->processId, 'running');

        $idleSince = null;

        while (true) {
            $budget = $expiresAt - time() - self::LEASE_HEADROOM_SECONDS;

            if ($budget <= 0) {
                $runningProcesses->reportStatus($this->processId, 'stopping');
                $runningProcesses->stop($this->processId);
                $this->startNewProcess($config, $laneId, $runningProcesses);
                return;
            }

            if (time() - $leaseCheckedAt >= self::LEASE_RECHECK_SECONDS) {
                $leaseCheckedAt = time();

                if (!$runningProcesses->isActive($laneId, $this->processId)) {
                    $runningProcesses->reportStatus($this->processId, 'stopping');
                    $runningProcesses->stop($this->processId);
                    $this->startNewProcess($config, $laneId, $runningProcesses);
                    return;
                }

                $expiresAt = time() + $runningProcesses->timeLeft($this->processId);
            }

            $result = $runner->poll($budget);

            // A poll that timed out before its first event read nothing but is NOT idle.
            if (!$result->isIdle()) {
                $idleSince = null;
                continue;
            }

            $idleSince ??= time();

            if (time() - $idleSince >= $config->keepAliveInSeconds) {
                $runningProcesses->reportStatus($this->processId, 'stopping');
                $runningProcesses->stop($this->processId);
                return;
            }

            usleep($config->sleepInMicroseconds);
        }
    }

    private function startNewProcess(LaneConfig $config, string $laneId, RunningProcesses $runningProcesses): void
    {
        $newProcessId = Ulid::generate();

        try {
            $runningProcesses->start(
                subscriptionId: $laneId,
                processId: $newProcessId,
                expiresAt: (new DateTime('now'))->add(new DateInterval("PT{$config->processTimeoutInSeconds}S")),
            );
        } catch (StartProcessException) {
            return;
        }

        LaneProcessManager::dispatchPollJob($this->laneName, $newProcessId, $config);
    }
}
