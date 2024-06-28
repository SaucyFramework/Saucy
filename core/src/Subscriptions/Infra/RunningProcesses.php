<?php

namespace Saucy\Core\Subscriptions\Infra;

interface RunningProcesses
{
    public function start(
        string $subscriptionId,
        string $processId,
        \DateTime $expiresAt,
        bool $ignorePaused = false,
    ): void;

    public function isActive(string $subscriptionId, ?string $processId = null): bool;

    public function timeLeft(string $processId): int;

    public function stop(string $processId): void;

    public function pause(string $subscriptionId, ?string $reason = null): void;

    public function resume(string $subscriptionId): void;

    /**
     * @return array<RunningProcess>
     */
    public function all(): array;

    public function reportStatus(string $processId, string $status): void;

    public function isPaused(string $subscriptionId): bool;

}
