<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Infra;

/**
 * Gradual migration from SQL to DynamoDB.
 * All writes go to DynamoDB. Reads check DynamoDB first, fall back to SQL.
 * Pause/resume state is managed in DynamoDB only once migrated.
 */
final readonly class MigratingRunningProcesses implements RunningProcesses
{
    public function __construct(
        private RunningProcesses $dynamoDb,
        private RunningProcesses $sql,
    ) {}

    public function start(string $subscriptionId, string $processId, \DateTime $expiresAt, bool $ignorePaused = false): void
    {
        // Clean up any SQL process for this subscription (migration)
        try {
            $this->sql->stop($processId);
        } catch (\Exception) {
            // Process may not exist in SQL, safe to ignore
        }

        $this->dynamoDb->start($subscriptionId, $processId, $expiresAt, $ignorePaused);
    }

    public function isActive(string $subscriptionId, ?string $processId = null): bool
    {
        if ($this->dynamoDb->isActive($subscriptionId, $processId)) {
            return true;
        }

        return $this->sql->isActive($subscriptionId, $processId);
    }

    public function timeLeft(string $processId): int
    {
        $timeLeft = $this->dynamoDb->timeLeft($processId);
        if ($timeLeft > 0) {
            return $timeLeft;
        }

        return $this->sql->timeLeft($processId);
    }

    public function stop(string $processId): void
    {
        $this->dynamoDb->stop($processId);
        $this->sql->stop($processId);
    }

    public function pause(string $subscriptionId, ?string $reason = null): void
    {
        $this->dynamoDb->pause($subscriptionId, $reason);
    }

    public function resume(string $subscriptionId): void
    {
        $this->dynamoDb->resume($subscriptionId);
        $this->sql->resume($subscriptionId);
    }

    /**
     * @return array<RunningProcess>
     */
    public function all(): array
    {
        $dynamoProcesses = $this->dynamoDb->all();
        $sqlProcesses = $this->sql->all();

        $merged = [];
        foreach ($dynamoProcesses as $process) {
            $merged[$process->subscriptionId] = $process;
        }
        foreach ($sqlProcesses as $process) {
            if (!isset($merged[$process->subscriptionId])) {
                $merged[$process->subscriptionId] = $process;
            }
        }

        return array_values($merged);
    }

    public function reportStatus(string $processId, string $status): void
    {
        $this->dynamoDb->reportStatus($processId, $status);
    }

    public function isPaused(string $subscriptionId): bool
    {
        if ($this->dynamoDb->isPaused($subscriptionId)) {
            return true;
        }

        return $this->sql->isPaused($subscriptionId);
    }
}
