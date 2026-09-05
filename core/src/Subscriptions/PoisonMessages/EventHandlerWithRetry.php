<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final class EventHandlerWithRetry
{
    /**
     * Attempts to handle the event with exponential backoff.
     * Returns null on success, or the final exception if all retries are exhausted.
     */
    public static function handle(
        MessageConsumer $consumer,
        MessageConsumeContext $context,
        ?RetryPolicy $retryPolicy = null,
    ): ?RetryExhaustedResult {
        $retryPolicy ??= RetryPolicy::default();

        $startTime = microtime(true);
        $delayMs = $retryPolicy->initialDelayMs;
        $retryCount = 0;
        $lastException = null;

        while (true) {
            try {
                $consumer->handle($context);
                return null;
            } catch (\Throwable $e) {
                $lastException = $e;
                $retryCount++;

                $elapsedSeconds = microtime(true) - $startTime;
                if (($elapsedSeconds + ($delayMs / 1000)) >= $retryPolicy->maxTotalSeconds) {
                    break;
                }

                usleep($delayMs * 1000);
                $delayMs *= $retryPolicy->multiplier;
            }
        }

        return new RetryExhaustedResult(
            exception: $lastException,
            retryCount: $retryCount,
        );
    }
}
