<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

final readonly class RetryExhaustedResult
{
    public function __construct(
        public \Throwable $exception,
        public int $retryCount,
    ) {}
}
