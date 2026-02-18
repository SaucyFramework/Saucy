<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Metrics;

final readonly class ProjectionSnapshot
{
    public function __construct(
        public string $streamId,
        public int $position,
        public int $maxPosition,
        public \DateTime $recordedAt,
    ) {}
}
