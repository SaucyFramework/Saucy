<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Follows the event-store head for the projection worker and tells it which event types arrived.
 *
 * One cheap primary-key range read per tick (`global_position > safeHead`) replaces 286 subscriptions
 * each polling on their own; only subscriptions that consume a type in the window get polled.
 *
 * GAPS. `global_position` is AUTO_INCREMENT, and MySQL hands the value out at INSERT time, not at
 * COMMIT time. A slow transaction can hold position 101 invisible while 102 is already committed and
 * readable. A subscription that polls at that moment handles 102, moves its checkpoint past 101, and
 * never sees 101 once it commits. So the watcher only releases positions up to the first hole
 * (`safeHead`), and the worker never lets a poll read past it (see BoundedAllStreamReader).
 * Not every hole fills: a rolled-back transaction or a failed insert (an optimistic-lock conflict on
 * `(stream_name, stream_position)` burns its id too) leaves a permanent one.
 *
 * TELLING THEM APART. With a primary connection, a hole older than `probeAfterSeconds` is probed with
 * `SELECT ... WHERE global_position BETWEEN ? AND ? FOR SHARE NOWAIT` on the primary:
 *   - error 3572 (lock not available): a transaction still holds the row, so keep waiting, up to
 *     `inFlightTimeoutSeconds`;
 *   - rows returned: committed on the primary, the replica just hasn't applied it yet, so keep waiting;
 *   - nothing returned: nobody holds those positions any more (rolled back or failed), so skip at once.
 * A permanent hole therefore costs about `probeAfterSeconds` of latency instead of a timeout, and a
 * slow transaction is waited for instead of skipped. Without a primary connection, or when the probe
 * is not supported, a hole is skipped after `gapTimeoutSeconds`.
 *
 * Skipped holes are remembered for `skippedRetentionSeconds`; `lateFills()` reports any that commit
 * after all (a transaction past the in-flight timeout, or a probe that was wrong), so a missed event
 * shows up in logs and metrics instead of passing silently.
 *
 * READ COPY. Pass a replica connection: the watcher reads several times a second and must not load
 * the primary. Replicas apply transactions in commit order, so a hole looks the same there and
 * everything up to the replica's safe head is committed on the primary too. Replica lag only adds
 * latency.
 */
final class HeadWatcher
{
    private ?int $safeHead = null;

    /** @var list<array{from: int, to: int, since: float}> holes after safeHead, oldest first */
    private array $openGaps = [];

    /** @var list<array{from: int, to: int, skippedAt: float, reported: array<int, true>}> */
    private array $skippedGaps = [];

    private int $skippedGapCount = 0;

    private int $timedOutGapCount = 0;

    private int $probeCount = 0;

    private bool $probeEnabled;

    private float $probeRetryAt = 0.0;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock seconds; injectable for tests
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly float $gapTimeoutSeconds = 5.0,
        private readonly int $windowSize = 1000,
        private readonly int $bootLookback = 1000,
        private readonly float $skippedRetentionSeconds = 900.0,
        private readonly string $table = 'event_store',
        ?\Closure $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ConnectionInterface $primary = null,
        private readonly float $probeAfterSeconds = 0.25,
        private readonly float $inFlightTimeoutSeconds = 30.0,
        private readonly ?float $bootLookbackSeconds = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->probeEnabled = $primary !== null;
    }

    /**
     * Starts below the current head, so the worker sees again what happened just before it started.
     *
     *   - Per-aggregate triggers are only kept in memory. A worker that joins (scale-out, a restart,
     *     the new version after a deploy) owns triggers that others saw before it was there. So it
     *     looks back `bootLookbackSeconds` (longer than workers keep triggers), and to `rewindTo`:
     *     the head when the deploy paused the workers.
     *   - Holes in that stretch may belong to transactions still in flight, so they are treated like
     *     any new hole.
     *
     * Never more than `bootLookback` positions.
     *
     * @return int the head at boot
     */
    public function boot(?int $rewindTo = null): int
    {
        $max = $this->connection->table($this->table)->max('global_position');
        $head = is_numeric($max) ? (int) $max : 0;
        $floor = max(0, $head - $this->bootLookback);
        $start = $this->bootLookbackSeconds === null
            ? $floor
            : $this->firstPositionSince($floor, $head, ($this->clock)() - $this->bootLookbackSeconds) - 1;
        if ($rewindTo !== null) {
            $start = min($start, $rewindTo);
        }
        $this->safeHead = max($floor, $start, 0);
        $this->openGaps = [];

        return $head;
    }

    /**
     * Binary search over the primary key for the first event recorded at or after `$since`
     * (`created_at` has no index, but it rises with `global_position`).
     */
    private function firstPositionSince(int $low, int $high, float $since): int
    {
        $cutoff = (new \DateTimeImmutable('@' . (int) $since))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            /** @var object{global_position: int|string, created_at: string}|null $row */
            $row = $this->connection->table($this->table)
                ->select(['global_position', 'created_at'])
                ->where('global_position', '>=', $mid)
                ->orderBy('global_position')
                ->first();
            if ($row === null || $row->created_at >= $cutoff) {
                $high = $mid;
            } else {
                $low = (int) $row->global_position + 1;
            }
        }

        return $high;
    }

    public function safeHead(): int
    {
        return $this->safeHead ?? throw new \LogicException('HeadWatcher::boot() must run first');
    }

    public function openGapCount(): int
    {
        return count($this->openGaps);
    }

    public function skippedGapCount(): int
    {
        return $this->skippedGapCount;
    }

    /**
     * Holes skipped because they stayed open too long, not because the probe showed them empty.
     * These are the ones that can turn into late fills.
     */
    public function timedOutGapCount(): int
    {
        return $this->timedOutGapCount;
    }

    /**
     * Locking reads this watcher sent to the primary.
     */
    public function probeCount(): int
    {
        return $this->probeCount;
    }

    /**
     * Seconds the oldest hole has been open, 0 when there is none.
     */
    public function oldestOpenGapAge(): float
    {
        return $this->openGaps === [] ? 0.0 : ($this->clock)() - $this->openGaps[0]['since'];
    }

    /**
     * Reads what landed after the safe head and releases it up to the first hole that is still young.
     * Returns null when the safe head did not move.
     */
    public function poll(): ?HeadWindow
    {
        $from = $this->safeHead();
        $now = ($this->clock)();

        $rows = $this->connection->table($this->table)
            ->select(['global_position', 'message_type', 'stream_name', 'stream_position', 'created_at'])
            ->where('global_position', '>', $from)
            ->orderBy('global_position')
            ->limit($this->windowSize)
            ->get();

        $expected = $from + 1;
        $safeHead = $from;
        $blocked = false;
        $probes = 0;
        $observedGaps = [];
        $eventTypes = [];
        $aggregateStreams = [];
        $aggregateStreamPositions = [];
        $oldestEventAt = null;

        foreach ($rows as $row) {
            $position = (int) $row->global_position;

            if ($position > $expected) {
                $since = $this->gapOpenSince($expected, $position - 1, $now);

                if (!$blocked && $this->resolve($expected, $position - 1, $now - $since, $probes)) {
                    $this->skip($expected, $position - 1, $now, $since);
                } else {
                    $blocked = true;
                    $observedGaps[] = ['from' => $expected, 'to' => $position - 1, 'since' => $since];
                }
            }

            $expected = $position + 1;

            if ($blocked) {
                continue;
            }

            $safeHead = $position;
            $type = (string) $row->message_type;
            $eventTypes[$type] = true;

            $streamName = (string) $row->stream_name;
            if (str_contains($streamName, '###')) {
                $aggregateStreams[$streamName][$type] = true;
                $aggregateStreamPositions[$streamName] = max($aggregateStreamPositions[$streamName] ?? 0, (int) $row->stream_position);
            }

            $oldestEventAt ??= $this->parseCreatedAt($row->created_at);
        }

        $this->openGaps = $observedGaps;

        if ($safeHead === $from) {
            return null;
        }

        $this->safeHead = $safeHead;

        return new HeadWindow(
            from: $from,
            to: $safeHead,
            eventTypes: array_keys($eventTypes),
            aggregateStreams: array_map(static fn(array $types): array => array_keys($types), $aggregateStreams),
            oldestEventAt: $oldestEventAt,
            full: count($rows) >= $this->windowSize,
            aggregateStreamPositions: $aggregateStreamPositions,
        );
    }

    /**
     * Events that committed inside a hole the watcher already skipped. Each is reported once. The
     * worker never delivered these to subscriptions that consume their type.
     *
     * @return list<array{position: int, eventType: string, streamName: string}>
     */
    public function lateFills(): array
    {
        $now = ($this->clock)();
        $this->skippedGaps = array_values(array_filter(
            $this->skippedGaps,
            fn(array $gap): bool => $now - $gap['skippedAt'] < $this->skippedRetentionSeconds,
        ));

        if ($this->skippedGaps === []) {
            return [];
        }

        $query = $this->connection->table($this->table)
            ->select(['global_position', 'message_type', 'stream_name'])
            ->where(function ($query) {
                foreach ($this->skippedGaps as $gap) {
                    $query->orWhereBetween('global_position', [$gap['from'], $gap['to']]);
                }
            })
            ->orderBy('global_position');

        $fills = [];
        foreach ($query->get() as $row) {
            $position = (int) $row->global_position;
            foreach ($this->skippedGaps as $index => $gap) {
                if ($position < $gap['from'] || $position > $gap['to'] || isset($gap['reported'][$position])) {
                    continue;
                }
                $this->skippedGaps[$index]['reported'][$position] = true;
                $fills[] = [
                    'position' => $position,
                    'eventType' => (string) $row->message_type,
                    'streamName' => (string) $row->stream_name,
                ];
            }
        }

        return $fills;
    }

    /**
     * When a hole was first seen. A hole that partly filled keeps the age of the hole it came from,
     * so the remaining part cannot hold the head back for longer than the timeout.
     */
    private function gapOpenSince(int $from, int $to, float $now): float
    {
        $since = $now;
        foreach ($this->openGaps as $gap) {
            if ($gap['from'] <= $to && $gap['to'] >= $from) {
                $since = min($since, $gap['since']);
            }
        }

        return $since;
    }

    /**
     * Whether the hole `from..to`, open for `age` seconds, can be skipped now.
     */
    private function resolve(int $from, int $to, float $age, int &$probes): bool
    {
        $now = ($this->clock)();
        if (!$this->probeEnabled && $this->primary !== null && $now >= $this->probeRetryAt) {
            $this->probeEnabled = true;
        }

        if (!$this->probeEnabled) {
            if ($age >= $this->gapTimeoutSeconds) {
                $this->timedOutGapCount++;

                return true;
            }

            return false;
        }

        if ($age >= $this->inFlightTimeoutSeconds) {
            $this->timedOutGapCount++;
            $this->logger->warning('Saucy worker: a transaction held an event-store position past the in-flight timeout; skipping it', [
                'from' => $from,
                'to' => $to,
                'open_for_seconds' => round($age, 2),
            ]);

            return true;
        }

        // Too young to tell an INSERT still executing from an empty hole, or enough probes this tick.
        if ($age < $this->probeAfterSeconds || $probes >= 10) {
            return false;
        }
        $probes++;
        $this->probeCount++;

        if ($this->primary === null) {
            return $age >= $this->gapTimeoutSeconds;
        }

        try {
            $rows = $this->primary->select(
                "select global_position from {$this->table} where global_position between ? and ? for share nowait",
                [$from, $to],
                false,
            );
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 3572) {
                return false; // still held by an open transaction
            }
            // Not supported here, or the connection failed: fall back to the timeout for a minute.
            $this->probeEnabled = false;
            $this->probeRetryAt = $now + 60;
            $this->logger->warning('Saucy worker: gap probe failed; falling back to the gap timeout', ['error' => $e->getMessage()]);

            return $age >= $this->gapTimeoutSeconds;
        }

        // Rows: committed on the primary, the replica is behind. None: nobody holds them.
        return $rows === [];
    }

    private function skip(int $from, int $to, float $now, float $since): void
    {
        $this->skippedGaps[] = ['from' => $from, 'to' => $to, 'skippedAt' => $now, 'reported' => []];
        $this->skippedGapCount++;

        $this->logger->debug('Saucy worker: skipping event-store gap', [
            'from' => $from,
            'to' => $to,
            'open_for_seconds' => round($now - $since, 2),
        ]);
    }

    private function parseCreatedAt(mixed $createdAt): ?\DateTimeImmutable
    {
        if (!is_string($createdAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return null;
        }
    }
}
