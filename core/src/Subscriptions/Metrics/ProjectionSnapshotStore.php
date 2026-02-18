<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Metrics;

interface ProjectionSnapshotStore
{
    public function record(ProjectionSnapshot ...$snapshots): void;

    /**
     * @return array<ProjectionSnapshot>
     */
    public function getForStream(string $streamId, int $limit = 100): array;

    /**
     * @return array<ProjectionSnapshot>
     */
    public function getForStreamBetween(string $streamId, \DateTime $from, \DateTime $to): array;
}
