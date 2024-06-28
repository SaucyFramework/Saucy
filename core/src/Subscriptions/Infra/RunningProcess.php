<?php

namespace Saucy\Core\Subscriptions\Infra;

final readonly class RunningProcess
{
    public function __construct(
        public string $subscriptionId,
        public string $processId,
        public \DateTime $expiresAt,
        public bool $paused,
        public ?string $pausedReason = null,
        public ?string $status = null,
        public ?\DateTime $lastStatusAt = null,

    ) {
    }
}
