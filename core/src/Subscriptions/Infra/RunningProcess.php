<?php

namespace Saucy\Core\Subscriptions\Infra;

final readonly class RunningProcess
{
    public function __construct(
        string $subscriptionId,
        string $processId,
        \DateTime $expiresAt,
        bool $paused,
    ) {
    }
}
