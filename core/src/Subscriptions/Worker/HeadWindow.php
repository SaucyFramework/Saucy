<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * The events the head watcher released in one step: every position in `(from, to]` is either
 * visible or a gap that was given up on.
 */
final readonly class HeadWindow
{
    /**
     * @param list<string> $eventTypes distinct `message_type`s in the window
     * @param array<string, list<string>> $aggregateStreams aggregate stream name => its event types in the window
     * @param bool $full the read hit the window size, so more events may be waiting
     * @param array<string, int> $aggregateStreamPositions aggregate stream name => highest stream position in the window
     */
    public function __construct(
        public int $from,
        public int $to,
        public array $eventTypes,
        public array $aggregateStreams,
        public ?\DateTimeImmutable $oldestEventAt,
        public bool $full,
        public array $aggregateStreamPositions = [],
    ) {}
}
