<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * WorkerCoordination for the test suite: several workers in one process share one instance.
 */
final class InMemoryWorkerCoordination implements WorkerCoordination
{
    /** @var array<string, WorkerInfo> */
    private array $workers = [];

    private ?DeployGate $gate = null;

    /** @var array<string, string> */
    private array $runners = [];

    public function heartbeat(WorkerInfo $worker): void
    {
        $this->workers[$worker->id] = $worker;
    }

    public function liveWorkers(int $now): array
    {
        return array_values(array_filter($this->workers, fn(WorkerInfo $worker) => $worker->expiresAt > $now));
    }

    public function leave(string $workerId): void
    {
        unset($this->workers[$workerId]);
    }

    public function gate(): ?DeployGate
    {
        return $this->gate;
    }

    public function pauseForDeploy(string $sha, ?int $head = null): void
    {
        $this->gate = new DeployGate($sha, paused: true, pausedAt: $head, pausedAtTime: time());
    }

    public function liftPause(string $sha): bool
    {
        if ($this->gate === null || !$this->gate->admits($sha) || !$this->gate->paused) {
            return false;
        }
        $this->gate = new DeployGate($sha, paused: false, pausedAt: $this->gate->pausedAt, pausedAtTime: $this->gate->pausedAtTime);

        return true;
    }

    public function clearGate(): void
    {
        $this->gate = null;
    }

    public function runnerOverrides(): array
    {
        return $this->runners;
    }

    public function setRunnerOverride(string $subscriptionId, ?string $runner): void
    {
        if ($runner === null) {
            unset($this->runners[$subscriptionId]);
        } else {
            $this->runners[$subscriptionId] = $runner;
        }
    }
}
