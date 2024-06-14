<?php

namespace Saucy\Core\Subscriptions\Infra;

interface RunningProcesses
{
    public function start(
        string $subscriptionId,
        string $processId,
        \DateTime $expiresAt
    ): void;

    public function isActive(string $subscriptionId, ?string $processId = null): bool;

    public function timeLeft(string $processId): int;

    public function stop(string $processId): void;
}
