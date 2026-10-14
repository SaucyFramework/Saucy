<?php

declare(strict_types=1);

namespace Saucy\Core\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\RunningProcessLeases;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Worker\EmbeddedMetricFormatMetrics;
use Saucy\Core\Subscriptions\Worker\HeadWatcher;
use Saucy\Core\Subscriptions\Worker\ProjectionWorker;
use Saucy\Core\Subscriptions\Worker\SubscriptionRunners;
use Saucy\Core\Subscriptions\Worker\WorkerCoordination;
use Saucy\Core\Subscriptions\Worker\WorkerOptions;

/**
 * Runs projectors and reactors in one long-lived process instead of a queue job per event. Run one
 * per container; see ProjectionWorker.
 *
 * Like `queue:work` it is meant to be restarted: it exits after --max-time, --max-events or
 * --memory, and the orchestrator starts a fresh one. SIGTERM finishes the current poll, hands the
 * locks back and exits.
 */
final class WorkCommand extends Command
{
    protected $signature = 'saucy:work
        {--max-time=3600 : Exit after this many seconds (0 = no limit)}
        {--max-events=0 : Exit after handling this many events (0 = no limit)}
        {--memory=768 : Exit once memory use reaches this many MB (0 = no limit)}
        {--only=* : Only run these subscription ids}
        {--except=* : Never run these subscription ids}
        {--no-stream-subscriptions : Leave the per-aggregate projectors to the queue}
        {--health-file= : Touch this file every loop iteration, for a container health check}';

    protected $description = 'Run Saucy projections in a long-running worker';

    public function handle(
        AllStreamSubscriptionRegistry $allStreamRegistry,
        StreamSubscriptionRegistry $streamRegistry,
        RunningProcesses $runningProcesses,
        WorkerCoordination $coordination,
        SubscriptionRunners $runners,
        DatabaseManager $db,
    ): int {
        /** @var array<string, mixed> $config */
        $config = config('saucy.worker');

        if (!($config['enabled'] ?? false)) {
            $this->error('The projection worker is not enabled: set saucy.worker.enabled (SAUCY_WORKER_ENABLED=true).');

            return self::FAILURE;
        }
        if (!$runningProcesses instanceof RunningProcessLeases) {
            $this->error(sprintf('%s does not implement %s, which the worker needs to hold leases.', get_class($runningProcesses), RunningProcessLeases::class));

            return self::FAILURE;
        }
        if (($config['sha'] ?? null) === null) {
            $this->warn('SAUCY_WORKER_SHA is not set: this worker ignores the deploy gate.');
        }

        foreach ($db->getConnections() as $connection) {
            $connection->disableQueryLog();
        }

        $logger = Log::channel();
        /** @var string|null $headConnection */
        $headConnection = $config['head_connection'] ?? null;
        $primary = $db->connection();

        $worker = new ProjectionWorker(
            allStreamRegistry: $allStreamRegistry,
            streamRegistry: $streamRegistry,
            runningProcesses: $runningProcesses,
            coordination: $coordination,
            headWatcher: new HeadWatcher(
                connection: $db->connection($headConnection),
                gapTimeoutSeconds: $this->float($config, 'gap_timeout_seconds', 5),
                windowSize: $this->int($config, 'window_size', 1000),
                bootLookback: $this->int($config, 'boot_lookback', 200000),
                logger: $logger,
                // The probe must see open transactions: it reads the primary, never a replica.
                primary: ($config['gap_probe'] ?? true) && $primary->getDriverName() === 'mysql' ? $primary : null,
                inFlightTimeoutSeconds: $this->float($config, 'in_flight_timeout_seconds', 30),
                bootLookbackSeconds: $this->float($config, 'boot_lookback_seconds', 120),
            ),
            options: new WorkerOptions(
                sha: isset($config['sha']) && is_string($config['sha']) ? $config['sha'] : null,
                headPollIntervalSeconds: $this->int($config, 'head_poll_interval_ms', 150) / 1000,
                leaseTtlSeconds: $this->int($config, 'lease_ttl_seconds', 30),
                leaseRenewBeforeSeconds: $this->int($config, 'lease_renew_before_seconds', 20),
                staleLockGraceSeconds: $this->int($config, 'stale_lock_grace_seconds', 5),
                leaseLingerSeconds: $this->float($config, 'lease_linger_seconds', 15),
                pauseCheckIntervalSeconds: $this->float($config, 'pause_check_interval_seconds', 2),
                heartbeatIntervalSeconds: $this->float($config, 'heartbeat_interval_seconds', 5),
                heartbeatTtlSeconds: $this->int($config, 'heartbeat_ttl_seconds', 20),
                gateCheckIntervalSeconds: $this->float($config, 'gate_check_interval_seconds', 1),
                sweepIntervalSeconds: $this->float($config, 'sweep_interval_seconds', 300),
                sliceSeconds: $this->float($config, 'slice_seconds', 2),
                metricsIntervalSeconds: $this->float($config, 'metrics_interval_seconds', 10),
                activityLog: (bool) ($config['activity_log'] ?? true),
                streamSubscriptions: !$this->option('no-stream-subscriptions'),
                only: $this->list('only'),
                except: $this->list('except'),
                maxSeconds: (int) $this->option('max-time'),
                maxEvents: (int) $this->option('max-events'),
                memoryLimitMb: (int) $this->option('memory'),
                healthFile: is_string($this->option('health-file')) && $this->option('health-file') !== '' ? $this->option('health-file') : null,
                retryBudgetSeconds: $this->float($config, 'retry_budget_seconds', 5),
                // Longer than any process caches a runner switch, so a switch back to the queue never
                // leaves an event to nobody.
                runnerHandoverSeconds: max(10.0, 2 * $runners->cacheSeconds() + 2),
            ),
            logger: $logger,
            metrics: ($config['emf_metrics'] ?? false) ? new EmbeddedMetricFormatMetrics(STDOUT, (string) config('app.env')) : null, // @phpstan-ignore-line
            resetScope: $this->resetScope(...),
            reportException: static fn(\Throwable $e) => report($e),
            runners: $runners,
        );

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, static fn() => $worker->stop());
            }
        }

        $this->info("Saucy worker {$worker->workerId} running.");
        $worker->run();

        return self::SUCCESS;
    }

    /**
     * What Laravel's queue worker resets between jobs (QueueServiceProvider::registerWorker()).
     */
    private function resetScope(): void
    {
        $app = $this->laravel;
        $log = $app->make('log');

        if (method_exists($log, 'flushSharedContext')) {
            $log->flushSharedContext();
        }
        if (method_exists($log, 'withoutContext')) {
            $log->withoutContext();
        }

        /** @var DatabaseManager $db */
        $db = $app->make('db');
        foreach ($db->getConnections() as $connection) {
            $connection->resetTotalQueryDuration();
            $connection->allowQueryDurationHandlersToRunAgain();
        }

        $app->forgetScopedInstances();
        Facade::clearResolvedInstances();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function float(array $config, string $key, float $default): float
    {
        return is_numeric($config[$key] ?? null) ? (float) $config[$key] : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function int(array $config, string $key, int $default): int
    {
        return is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $value = $this->option($option);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
