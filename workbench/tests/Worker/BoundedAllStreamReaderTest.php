<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\BoundedAllStreamReader;
use Saucy\Core\Subscriptions\Worker\PositionCap;
use Generator;
use PHPUnit\Framework\TestCase;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\StoredEvent;

/**
 * a worker poll never sees, or moves its checkpoint to, a position past the safe head.
 */
final class BoundedAllStreamReaderTest extends TestCase
{
    public function test_events_past_the_cap_are_hidden(): void
    {
        $cap = new PositionCap(11);
        $reader = new BoundedAllStreamReader($this->reader([10, 11, 13, 14], max: 14), $cap);

        $positions = array_map(
            fn(StoredEvent $event) => $event->globalPosition,
            iterator_to_array($reader->paginate(new AllStreamQuery(0, 100)), false),
        );

        $this->assertSame([10, 11], $positions);
        $this->assertSame(11, $reader->maxEventId());

        $cap->position = 20;
        $this->assertSame(14, $reader->maxEventId());
    }

    /**
     * @param list<int> $positions
     */
    private function reader(array $positions, int $max): AllStreamReader
    {
        return new class ($positions, $max) implements AllStreamReader {
            public function __construct(private array $positions, private int $max) {}

            public function paginate(AllStreamQuery $streamQuery): Generator
            {
                foreach ($this->positions as $position) {
                    yield new StoredEvent(
                        eventId: "event-{$position}",
                        eventType: 'a',
                        streamNameType: 'aggregate_stream_name',
                        streamType: 'test',
                        streamName: "test###{$position}",
                        streamPosition: 1,
                        payloadJson: '{}',
                        metadataJson: '{}',
                        globalPosition: $position,
                        createdAt: new \DateTimeImmutable(),
                    );
                }
            }

            public function maxEventId(): int
            {
                return $this->max;
            }
        };
    }
}
