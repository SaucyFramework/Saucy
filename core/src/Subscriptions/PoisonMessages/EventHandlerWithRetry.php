<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final class EventHandlerWithRetry
{
    private const int INITIAL_DELAY_MS = 100;
    private const int MULTIPLIER = 2;
    public const int MAX_TOTAL_SECONDS = 60;

    /**
     * Attempts to handle the event with exponential backoff.
     * Returns null on success, or the final exception if all retries are exhausted.
     * An error that cannot change on retry (see isRetryable) is returned at once, without backing off.
     *
     * @param float $maxTotalSeconds how long to keep retrying in-line. A queue job can afford the
     *     default minute; the long-running worker handles every subscription in one process, so it
     *     passes a few seconds and backs the subscription off instead.
     */
    public static function handle(MessageConsumer $consumer, MessageConsumeContext $context, float $maxTotalSeconds = self::MAX_TOTAL_SECONDS): ?RetryExhaustedResult
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

                if (!self::isRetryable($e)) {
                    break;
                }

                $elapsedSeconds = microtime(true) - $startTime;
                if (($elapsedSeconds + ($delayMs / 1000)) >= $maxTotalSeconds) {
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

    /**
     * Whether another attempt can succeed. Integrity and data errors (SQLSTATE class 23 and 22) and
     * TypeErrors give the same result every time, so retrying them only holds the caller (possibly an
     * open transaction, in the in-sync path) for the whole budget. Everything else keeps backing off:
     * deadlocks (40001), lock wait timeouts, and domain races such as a profile that is not created yet.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \TypeError) {
                return false;
            }

            if ($current instanceof \PDOException && preg_match('/^2[23]/', (string) $current->getCode()) === 1) {
                return false;
            }
        }

        return true;
    }
}
