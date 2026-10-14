<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Tuning for the projection worker. Defaults live in config('saucy.worker').
 */
final readonly class WorkerOptions
{
    /**
     * @param list<string> $only subscription ids to run; empty runs all
     * @param list<string> $except subscription ids to leave alone
     */
    public function __construct(
        public ?string $sha = null,
        public float $headPollIntervalSeconds = 0.15,
        public int $leaseTtlSeconds = 30,
        public int $leaseRenewBeforeSeconds = 20,
        public int $staleLockGraceSeconds = 5,
        public float $leaseLingerSeconds = 15.0,
        public float $pauseCheckIntervalSeconds = 2.0,
        public float $heartbeatIntervalSeconds = 5.0,
        public int $heartbeatTtlSeconds = 20,
        public float $gateCheckIntervalSeconds = 1.0,
        public float $sweepIntervalSeconds = 300.0,
        public float $sliceSeconds = 2.0,
        public float $metricsIntervalSeconds = 10.0,
        public float $lateFillCheckIntervalSeconds = 30.0,
        public bool $activityLog = true,
        public bool $streamSubscriptions = true,
        public array $only = [],
        public array $except = [],
        public int $maxSeconds = 0,
        public int $maxEvents = 0,
        public int $memoryLimitMb = 0,
        public ?string $healthFile = null,
        public ?float $retryBudgetSeconds = 5.0,
        public float $runnerHandoverSeconds = 10.0,
    ) {}

    public function runs(string $subscriptionId): bool
    {
        if ($this->only !== [] && !in_array($subscriptionId, $this->only, true)) {
            return false;
        }

        return !in_array($subscriptionId, $this->except, true);
    }
}
