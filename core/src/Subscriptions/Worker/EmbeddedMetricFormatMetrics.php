<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Writes worker metrics as CloudWatch Embedded Metric Format lines.
 *
 * On ECS the container's stdout goes to CloudWatch Logs, which turns each line into metrics with no
 * API calls or agent. `ProjectionLagSeconds` is what the service autoscales and alarms on.
 */
final readonly class EmbeddedMetricFormatMetrics implements WorkerMetrics
{
    private const UNITS = [
        'ProjectionLagSeconds' => 'Seconds',
        'PendingSubscriptions' => 'Count',
        'HeldLeases' => 'Count',
        'OwnedSubscriptions' => 'Count',
        'EventsHandled' => 'Count',
        'OpenGaps' => 'Count',
        'OldestOpenGapSeconds' => 'Seconds',
        'SkippedGaps' => 'Count',
        'GapTimeouts' => 'Count',
        'GapProbes' => 'Count',
        'LateGapFills' => 'Count',
        'FailedPolls' => 'Count',
    ];

    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
        private string $environment,
        private string $namespace = 'Saucy/Worker',
    ) {}

    /**
     * @param array<string, int|float> $values metric name => value
     */
    public function emit(string $workerId, array $values): void
    {
        $metrics = [];
        foreach (array_keys($values) as $name) {
            $metrics[] = ['Name' => $name, 'Unit' => self::UNITS[$name] ?? 'None'];
        }

        $line = json_encode([
            '_aws' => [
                'Timestamp' => (int) (microtime(true) * 1000),
                'CloudWatchMetrics' => [[
                    'Namespace' => $this->namespace,
                    'Dimensions' => [['Environment']],
                    'Metrics' => $metrics,
                ]],
            ],
            'Environment' => $this->environment,
            'WorkerId' => $workerId,
        ] + $values, JSON_THROW_ON_ERROR);

        fwrite($this->stream, $line . "\n");
    }
}
