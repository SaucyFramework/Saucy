<?php

namespace Saucy\Core\Subscriptions\Gaps;

use DateTimeImmutable;

final readonly class Gap
{
    public function __construct(
        public int $position,
        public DateTimeImmutable $firstSeenAt,
    ) {}

    public function isExpired(DateTimeImmutable $now, int $gapTimeoutSeconds): bool
    {
        return ($now->getTimestamp() - $this->firstSeenAt->getTimestamp()) >= $gapTimeoutSeconds;
    }
}
