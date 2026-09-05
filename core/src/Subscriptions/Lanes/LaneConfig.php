<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Subscriptions\PoisonMessages\RetryPolicy;

/**
 * Resolved settings for a single projection lane.
 */
final readonly class LaneConfig
{
    public const string DEFAULT_LANE = 'default';

    public function __construct(
        public string $name,
        public ?string $queue = null,
        public int $pageSize = 100,
        public int $processTimeoutInSeconds = 240,
        public int $keepAliveInSeconds = 30,
        public int $sleepInMicroseconds = 250_000,
        public int $catchUpThreshold = 1000,
        public ?int $commitBatchSize = null,
        public int $retryBudgetInSeconds = 10,
        public int $quiesceWaitInSeconds = 20,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            queue: self::nullableString($config, 'queue'),
            pageSize: self::int($config, 'page_size', 100),
            processTimeoutInSeconds: self::int($config, 'process_timeout', 240),
            keepAliveInSeconds: self::int($config, 'keep_alive_seconds', 30),
            sleepInMicroseconds: self::int($config, 'sleep_ms', 250) * 1000,
            catchUpThreshold: self::int($config, 'catch_up_threshold', 1000),
            commitBatchSize: self::nullableInt($config, 'commit_batch_size'),
            retryBudgetInSeconds: self::int($config, 'retry_budget_seconds', 10),
            quiesceWaitInSeconds: self::int($config, 'quiesce_wait_seconds', 20),
        );
    }

    /**
     * Number of events handled between checkpoint commits. Defaults to a commit per page.
     */
    public function effectiveCommitBatchSize(): int
    {
        return $this->commitBatchSize ?? $this->pageSize;
    }

    public function retryPolicy(): RetryPolicy
    {
        return new RetryPolicy(maxTotalSeconds: $this->retryBudgetInSeconds);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableInt(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
