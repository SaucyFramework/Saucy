<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Which runner each subscription uses: a configured default, overridden per subscription at runtime.
 *
 * Overrides live in the WorkerCoordination store (`saucy:runner`), so moving a projection to the
 * worker, or back, needs no deploy. The `*` override replaces the configured default. Reads are
 * cached for `cacheSeconds`: this is consulted on every persist, and a switch only needs to land
 * within a second or two.
 *
 * Reads happen at most once per `cacheSeconds` per process, not per event. Once a migration is
 * complete, set `readOverrides: false` (saucy.worker.runner_overrides): only the configured default
 * applies and the store is never read. With default `worker` the after-persist hook then dispatches
 * nothing at all.
 *
 * Never breaks a persist: when disabled (the worker is not in use) it never touches the store, and
 * when the store cannot be read it keeps the last overrides it knew, or the configured default. Both
 * err towards the queue also triggering, which the shared locks make harmless.
 */
final class SubscriptionRunners
{
    public const DEFAULT_KEY = '*';

    /** @var array<string, Runner>|null */
    private ?array $overrides = null;

    private float $loadedAt = 0.0;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock seconds; injectable for tests
     */
    public function __construct(
        private readonly WorkerCoordination $coordination,
        private readonly Runner $default = Runner::Queue,
        private readonly float $cacheSeconds = 2.0,
        ?\Closure $clock = null,
        private readonly bool $enabled = true,
        private readonly bool $readOverrides = true,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function runner(string $subscriptionId): Runner
    {
        if (!$this->enabled) {
            return Runner::Queue;
        }

        $overrides = $this->overrides();

        return $overrides[$subscriptionId] ?? $overrides[self::DEFAULT_KEY] ?? $this->default;
    }

    public function runsOnWorker(string $subscriptionId): bool
    {
        return $this->runner($subscriptionId) === Runner::Worker;
    }

    /**
     * Whether any subscription may still need a queue trigger. When none does, the after-persist
     * hook can skip dispatching the TriggerSubscriptionsJob altogether.
     */
    public function queueRunsAny(): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $overrides = $this->overrides();
        if (($overrides[self::DEFAULT_KEY] ?? $this->default) === Runner::Queue) {
            return true;
        }

        return in_array(Runner::Queue, $overrides, true);
    }

    /**
     * @param string $subscriptionId a subscription id, or `*` for the default
     * @param Runner|null $runner null removes the override
     */
    public function set(string $subscriptionId, ?Runner $runner): void
    {
        if (!$this->enabled) {
            throw new \LogicException('The projection worker is not enabled (saucy.worker.enabled)');
        }
        if (!$this->readOverrides) {
            throw new \LogicException('Runner overrides are off (saucy.worker.runner_overrides): only saucy.worker.default_runner applies');
        }

        $this->coordination->setRunnerOverride($subscriptionId, $runner?->value);
        $this->overrides = null;
    }

    /**
     * @return array<string, Runner>
     */
    public function overrides(): array
    {
        if (!$this->enabled || !$this->readOverrides) {
            return [];
        }

        $now = ($this->clock)();
        if ($this->overrides === null || $now - $this->loadedAt >= $this->cacheSeconds) {
            $this->loadedAt = $now;
            try {
                $overrides = [];
                foreach ($this->coordination->runnerOverrides() as $subscriptionId => $runner) {
                    $parsed = Runner::tryFrom($runner);
                    if ($parsed !== null) {
                        $overrides[$subscriptionId] = $parsed;
                    }
                }
                $this->overrides = $overrides;
            } catch (\Throwable $e) {
                $this->overrides ??= [];
                report($e);
            }
        }

        return $this->overrides;
    }

    public function configuredDefault(): Runner
    {
        return $this->default;
    }

    public function readsOverrides(): bool
    {
        return $this->enabled && $this->readOverrides;
    }

    public function cacheSeconds(): float
    {
        return $this->cacheSeconds;
    }
}
