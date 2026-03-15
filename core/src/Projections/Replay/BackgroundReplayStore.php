<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

interface BackgroundReplayStore
{
    public function start(string $subscriptionId): void;

    public function getStatus(string $subscriptionId): ?ReplayStatus;

    public function markSwapping(string $subscriptionId): void;

    public function markCompleted(string $subscriptionId): void;

    public function markFailed(string $subscriptionId, string $reason): void;

    public function remove(string $subscriptionId): void;

    /**
     * @return array<string, ReplayStatus>
     */
    public function getAll(): array;
}
