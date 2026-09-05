<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use Saucy\Core\Subscriptions\Metrics\ActivityStreamLogger;
use Saucy\Core\Subscriptions\Metrics\SubscriptionActivity;

/**
 * Buffers a lane poll's activity rows so an idle poll writes nothing at all.
 *
 * A lane polls continuously while it is kept alive, so writing a `started_poll` /
 * `loading_events` / `loaded_events` trail per empty poll would dwarf the useful rows. The trail
 * is therefore only emitted for a poll that actually read an event (or timed out); poison rows
 * and `store_checkpoint` rows are always written.
 */
final class LaneActivityLog
{
    /** @var array<int, SubscriptionActivity> */
    private array $trail = [];

    /** @var array<int, SubscriptionActivity> */
    private array $rows = [];

    private bool $trailFlushed = false;

    public function __construct(
        private readonly ActivityStreamLogger $logger,
        private readonly string $laneStreamId,
    ) {}

    /**
     * A row that is only worth writing when the poll did some work.
     *
     * @param array<string, mixed> $data
     */
    public function trail(string $type, string $message, array $data = []): void
    {
        $this->trail[] = $this->activity($this->laneStreamId, $type, $message, $data);
    }

    /**
     * A row that is always written, under the lane's stream id.
     *
     * @param array<string, mixed> $data
     */
    public function record(string $type, string $message, array $data = []): void
    {
        $this->rows[] = $this->activity($this->laneStreamId, $type, $message, $data);
    }

    /**
     * A row that is always written, under a MEMBER's stream id (poison messages).
     *
     * @param array<string, mixed> $data
     */
    public function recordForMember(string $memberId, string $type, string $message, array $data = []): void
    {
        $this->rows[] = $this->activity($memberId, $type, $message, $data);
    }

    public function flush(bool $includeTrail): void
    {
        $toWrite = [];

        if ($includeTrail && !$this->trailFlushed) {
            $toWrite = $this->trail;
            $this->trail = [];
            $this->trailFlushed = true;
        }

        $toWrite = array_merge($toWrite, $this->rows);
        $this->rows = [];

        if ($toWrite === []) {
            return;
        }

        $this->logger->log(...$toWrite);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function activity(string $streamId, string $type, string $message, array $data): SubscriptionActivity
    {
        return new SubscriptionActivity(
            streamId: $streamId,
            type: $type,
            message: $message,
            occurredAt: new \DateTime('now'),
            data: $data,
        );
    }
}
