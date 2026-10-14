<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Shared state between projection workers: who is alive, which code version may run, and which
 * subscriptions they run (SubscriptionRunners).
 */
interface WorkerCoordination
{
    public function heartbeat(WorkerInfo $worker): void;

    /**
     * @return list<WorkerInfo> workers whose heartbeat has not expired
     */
    public function liveWorkers(int $now): array;

    public function leave(string $workerId): void;

    public function gate(): ?DeployGate;

    /**
     * Run by the deploy before migrations: every worker not on `sha` stops taking locks.
     */
    public function pauseForDeploy(string $sha, ?int $head = null): void;

    /**
     * @return bool whether this call lifted the pause (false when it was lifted already or the gate
     *     names another version)
     */
    public function liftPause(string $sha): bool;

    public function clearGate(): void;

    /**
     * @return array<string, string> subscription id (or `*` for the default) => runner
     */
    public function runnerOverrides(): array;

    /**
     * @param string|null $runner null removes the override
     */
    public function setRunnerOverride(string $subscriptionId, ?string $runner): void;
}
