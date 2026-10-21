<?php

declare(strict_types=1);

namespace Workbench\Tests;

use PHPUnit\Framework\TestCase;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\PoisonMessages\EventHandlerWithRetry;

/**
 * Errors that cannot change on retry must fail at once; transient ones keep backing off.
 */
final class EventHandlerWithRetryTest extends TestCase
{
    /**
     * @return array<string, array{\Throwable}>
     */
    public static function nonRetryable(): array
    {
        return [
            'integrity constraint violation (23000)' => [self::pdo('SQLSTATE[23000]: Integrity constraint violation', '23000')],
            'unique violation, postgres (23505)' => [self::pdo('duplicate key', '23505')],
            'data error (22001, data too long)' => [self::pdo('Data too long', '22001')],
            'TypeError' => [new \TypeError('Argument #1 must be of type string, null given')],
            'wrapped constraint violation' => [new \RuntimeException('projector failed', 0, self::pdo('NOT NULL', '23000'))],
        ];
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function retryable(): array
    {
        return [
            'deadlock (40001)' => [self::pdo('Deadlock found when trying to get lock', '40001')],
            'lock wait timeout (HY000)' => [self::pdo('Lock wait timeout exceeded', 'HY000')],
            'domain race' => [new \DomainException('ProfileNotYetCreated')],
        ];
    }

    /**
     * @dataProvider nonRetryable
     */
    public function test_it_fails_fast_on_errors_that_never_succeed_on_retry(\Throwable $error): void
    {
        $consumer = new ThrowingConsumer(static fn(int $attempt) => throw $error);

        $started = microtime(true);
        $result = EventHandlerWithRetry::handle($consumer, self::context(), 5);

        $this->assertNotNull($result);
        $this->assertSame($error, $result->exception);
        $this->assertSame(1, $consumer->attempts);
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    /**
     * @dataProvider retryable
     */
    public function test_it_keeps_backing_off_on_transient_errors(\Throwable $error): void
    {
        $consumer = new ThrowingConsumer(static fn(int $attempt) => throw $error);

        $result = EventHandlerWithRetry::handle($consumer, self::context(), 0.5);

        $this->assertNotNull($result);
        $this->assertGreaterThan(1, $consumer->attempts);
        $this->assertSame($consumer->attempts, $result->retryCount);
    }

    public function test_it_succeeds_when_a_transient_error_clears(): void
    {
        $consumer = new ThrowingConsumer(static function (int $attempt): void {
            if ($attempt < 3) {
                throw new \DomainException('ProfileNotYetCreated');
            }
        });

        $this->assertNull(EventHandlerWithRetry::handle($consumer, self::context(), 5));
        $this->assertSame(3, $consumer->attempts);
    }

    private static function pdo(string $message, string $sqlState): \PDOException
    {
        $e = new \PDOException($message);
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, $sqlState);

        return $e;
    }

    private static function context(): MessageConsumeContext
    {
        return (new \ReflectionClass(MessageConsumeContext::class))->newInstanceWithoutConstructor();
    }
}

final class ThrowingConsumer implements MessageConsumer
{
    public int $attempts = 0;

    /**
     * @param \Closure(int): void $behaviour
     */
    public function __construct(private \Closure $behaviour) {}

    public function handle(MessageConsumeContext $context): void
    {
        ($this->behaviour)(++$this->attempts);
    }

    public static function getMessages(): array
    {
        return [];
    }
}
