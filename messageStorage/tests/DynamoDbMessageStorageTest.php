<?php

namespace Saucy\MessageStorage\Tests;

use Aws\DynamoDb\DynamoDbClient;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use EventSauce\EventSourcing\UnableToPersistMessages;
use PHPUnit\Framework\TestCase;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\DynamoDb\DynamoDbMessageStorage;
use Saucy\MessageStorage\DynamoDb\DynamoDbTableManager;
use Saucy\MessageStorage\Serialization\ConstructingPayloadSerializer;
use Saucy\MessageStorage\Serialization\EventSerializer;

final class DynamoDbMessageStorageTest extends TestCase
{
    private DynamoDbClient $dynamoDbClient;
    private EventSerializer $eventSerializer;
    private TypeMap $typeMap;
    private DynamoDbMessageStorage $storage;
    private DynamoDbTableManager $tableManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Use real DynamoDB Local client
        $this->dynamoDbClient = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => 'http://localhost:8000',
            'credentials' => [
                'key' => 'dummy',
                'secret' => 'dummy',
            ],
        ]);

        $this->tableManager = new DynamoDbTableManager($this->dynamoDbClient);
        $this->tableManager->ensureEventStoreTable('test_event_store');

        $this->typeMap = new TypeMap([
            TestEvent::class => 'test.event',
            AggregateStreamName::class => 'aggregate_stream_name',
        ]);

        $this->eventSerializer = new ConstructingPayloadSerializer($this->typeMap);

        $this->storage = new DynamoDbMessageStorage(
            dynamoDbClient: $this->dynamoDbClient,
            eventSerializer: $this->eventSerializer,
            streamNameTypeMap: $this->typeMap,
            eventStoreTableName: 'test_event_store',
        );
    }

    protected function tearDown(): void
    {
        // Clean up table after each test
        try {
            $this->dynamoDbClient->deleteTable(['TableName' => 'test_event_store']);
        } catch (\Exception $e) {
            // Ignore if table doesn't exist
        }
        parent::tearDown();
    }

    /** @test */
    public function it_can_persist_a_single_event(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');
        $event = new StreamEvent(
            eventId: 'event-123',
            payload: new TestEvent(data: 'test'),
            metadata: ['meta' => 'value'],
            position: 1,
        );

        $this->storage->persist($streamName, $event);

        // Verify event was persisted
        $retrieved = iterator_to_array($this->storage->retrieveAllInStream($streamName));
        $this->assertCount(1, $retrieved);
        $this->assertEquals('event-123', $retrieved[0]->eventId);
        $this->assertEquals(1, $retrieved[0]->position);
    }

    /** @test */
    public function it_can_persist_multiple_events_in_transaction(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');
        $event1 = new StreamEvent('event-1', new TestEvent('data1'), [], 1);
        $event2 = new StreamEvent('event-2', new TestEvent('data2'), [], 2);
        $event3 = new StreamEvent('event-3', new TestEvent('data3'), [], 3);

        $this->storage->persist($streamName, $event1, $event2, $event3);

        // Verify all events were persisted
        $retrieved = iterator_to_array($this->storage->retrieveAllInStream($streamName));
        $this->assertCount(3, $retrieved);
        $this->assertEquals('event-1', $retrieved[0]->eventId);
        $this->assertEquals('event-2', $retrieved[1]->eventId);
        $this->assertEquals('event-3', $retrieved[2]->eventId);
    }

    /** @test */
    public function it_enforces_optimistic_locking_on_concurrent_writes(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');
        $event = new StreamEvent('event-123', new TestEvent('data'), [], 1);

        // First write succeeds
        $this->storage->persist($streamName, $event);

        // Second write with same position should fail
        $this->expectException(UnableToPersistMessages::class);
        $this->storage->persist($streamName, $event);
    }

    /** @test */
    public function it_retrieves_events_in_order(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');

        $events = [
            new StreamEvent('event-1', new TestEvent('data1'), [], 1),
            new StreamEvent('event-2', new TestEvent('data2'), [], 2),
            new StreamEvent('event-3', new TestEvent('data3'), [], 3),
        ];

        $this->storage->persist($streamName, ...$events);

        $retrieved = iterator_to_array($this->storage->retrieveAllInStream($streamName));
        $this->assertCount(3, $retrieved);
        $this->assertEquals(1, $retrieved[0]->position);
        $this->assertEquals(2, $retrieved[1]->position);
        $this->assertEquals(3, $retrieved[2]->position);
    }

    /** @test */
    public function it_retrieves_events_since_checkpoint(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');

        $events = [
            new StreamEvent('event-1', new TestEvent('data1'), [], 1),
            new StreamEvent('event-2', new TestEvent('data2'), [], 2),
            new StreamEvent('event-3', new TestEvent('data3'), [], 3),
        ];

        $this->storage->persist($streamName, ...$events);

        $retrieved = iterator_to_array($this->storage->retrieveAllInStreamSinceCheckpoint($streamName, 1));
        $this->assertCount(2, $retrieved);
        $this->assertEquals(2, $retrieved[0]->streamPosition);
        $this->assertEquals(3, $retrieved[1]->streamPosition);
        $this->assertNull($retrieved[0]->globalPosition);
    }

    /** @test */
    public function it_returns_max_stream_position(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');

        $maxPosition = $this->storage->maxStreamPosition($streamName);
        $this->assertEquals(0, $maxPosition);

        $events = [
            new StreamEvent('event-1', new TestEvent('data1'), [], 1),
            new StreamEvent('event-2', new TestEvent('data2'), [], 2),
            new StreamEvent('event-3', new TestEvent('data3'), [], 3),
        ];

        $this->storage->persist($streamName, ...$events);

        $maxPosition = $this->storage->maxStreamPosition($streamName);
        $this->assertEquals(3, $maxPosition);
    }

    /** @test */
    public function it_returns_zero_when_stream_does_not_exist(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-nonexistent');

        $maxPosition = $this->storage->maxStreamPosition($streamName);
        $this->assertEquals(0, $maxPosition);
    }

    /** @test */
    public function it_handles_multiple_streams_independently(): void
    {
        $stream1 = new AggregateStreamName('test_aggregate', 'agg-1');
        $stream2 = new AggregateStreamName('test_aggregate', 'agg-2');

        $this->storage->persist($stream1, new StreamEvent('event-1-1', new TestEvent('data'), [], 1));
        $this->storage->persist($stream2, new StreamEvent('event-2-1', new TestEvent('data'), [], 1));
        $this->storage->persist($stream1, new StreamEvent('event-1-2', new TestEvent('data'), [], 2));
        $this->storage->persist($stream2, new StreamEvent('event-2-2', new TestEvent('data'), [], 2));

        $retrieved1 = iterator_to_array($this->storage->retrieveAllInStream($stream1));
        $retrieved2 = iterator_to_array($this->storage->retrieveAllInStream($stream2));

        $this->assertCount(2, $retrieved1);
        $this->assertCount(2, $retrieved2);
        $this->assertEquals('event-1-1', $retrieved1[0]->eventId);
        $this->assertEquals('event-2-1', $retrieved2[0]->eventId);
    }

    /** @test */
    public function it_chunks_large_transactions(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-large');
        $events = [];
        for ($i = 1; $i <= 150; $i++) {
            $events[] = new StreamEvent("event-{$i}", new TestEvent("data{$i}"), [], $i);
        }

        // This should chunk into 100 + 50
        $this->storage->persist($streamName, ...$events);

        $retrieved = iterator_to_array($this->storage->retrieveAllInStream($streamName));
        $this->assertCount(150, $retrieved);
        $this->assertEquals(1, $retrieved[0]->position);
        $this->assertEquals(150, $retrieved[149]->position);
    }

    /** @test */
    public function it_does_nothing_when_persisting_zero_events(): void
    {
        $streamName = new AggregateStreamName('test_aggregate', 'agg-123');

        $this->storage->persist($streamName);

        $retrieved = iterator_to_array($this->storage->retrieveAllInStream($streamName));
        $this->assertCount(0, $retrieved);
    }
}

final class TestEvent implements SerializablePayload
{
    public function __construct(public string $data) {}

    public function toPayload(): array
    {
        return ['data' => $this->data];
    }

    public static function fromPayload(array $payload): static
    {
        return new static($payload['data']);
    }
}
