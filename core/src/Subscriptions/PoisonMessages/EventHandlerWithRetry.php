<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final class EventHandlerWithRetry
{
    private const int INITIAL_DELAY_MS = 100;
    private const int MULTIPLIER = 2;
    private const int MAX_TOTAL_SECONDS = 60;

    /**
     * Attempts to handle the event with exponential backoff.
     * Returns null on success, or the final exception if all retries are exhausted.
     */
    public static function handle(MessageConsumer $consumer, MessageConsumeContext $context): ?RetryExhaustedResult
    {
        $startTime = microtime(true);
        $delayMs = self::INITIAL_DELAY_MS;
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
                if (($elapsedSeconds + ($delayMs / 1000)) >= self::MAX_TOTAL_SECONDS) {
                    break;
                }

                usleep($delayMs * 1000);
                $delayMs *= self::MULTIPLIER;
            }
        }

        return new RetryExhaustedResult(
            exception: $lastException,
            retryCount: $retryCount,
        );
    }
}
