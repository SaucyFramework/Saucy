<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\PoisonMessages;

/**
 * Retry budget used by {@see EventHandlerWithRetry}.
 *
 * The defaults are the constants the handler used before this value object existed, so
 * callers that do not pass a policy keep the exact behaviour they had.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $initialDelayMs = 100,
        public int $multiplier = 2,
        public int $maxTotalSeconds = 60,
    ) {}

    public static function default(): self
    {
        return new self();
    }
}
