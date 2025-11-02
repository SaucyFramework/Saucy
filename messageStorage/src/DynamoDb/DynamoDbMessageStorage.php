<?php

namespace Saucy\MessageStorage\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use DateTimeImmutable;
use EventSauce\EventSourcing\UnableToPersistMessages;
use EventSauce\EventSourcing\UnableToRetrieveMessages;
use Generator;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\StoredEvent;
use Saucy\MessageStorage\StreamReader;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;

final readonly class DynamoDbMessageStorage implements AllStreamMessageRepository, StreamReader, AllStreamReader, ReadEventData
{
    public function __construct(
        private DynamoDbClient $dynamoDbClient,
        private EventSerializer $eventSerializer,
        private TypeMap $streamNameTypeMap,
        private string $eventStoreTableName = 'event_store',
        private AllStreamReader&ReadEventData $allStreamReader = new NoOpAllStream(),
    ) {}

    /**
     * @throws UnableToPersistMessages
     */
    public function persist(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        if (count($streamEvents) === 0) {
            return;
        }

        $streamNameType = $this->streamNameTypeMap->instanceToType($streamName);
        $streamType = $streamName->type();
        $streamNameString = $streamName->toString();

        try {
            // For single event, use PutItem with optimistic locking
            if (count($streamEvents) === 1) {
                $event = $streamEvents[0];
                $this->putSingleEvent($event, $streamNameString, $streamNameType, $streamType);
                return;
            }

            // For multiple events, use TransactWriteItems with optimistic locking
            $this->transactionalWriteEvents($streamEvents, $streamNameString, $streamNameType, $streamType);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                throw UnableToPersistMessages::dueTo('Stream position already exists. Optimistic locking failed.', $e);
            }
            throw UnableToPersistMessages::dueTo($e->getMessage(), $e);
        } catch (\Exception $e) {
            throw UnableToPersistMessages::dueTo($e->getMessage(), $e);
        }
    }

    /**
     * @return Generator<StreamEvent>
     * @throws UnableToRetrieveMessages
     */
    public function retrieveAllInStream(StreamName $streamName): Generator
    {
        try {
            $streamNameString = $streamName->toString();
            $lastEvaluatedKey = null;

            do {
                $params = [
                    'TableName' => $this->eventStoreTableName,
                    'KeyConditionExpression' => 'stream_name = :stream_name',
                    'ExpressionAttributeValues' => [
                        ':stream_name' => ['S' => $streamNameString],
                    ],
                    'ScanIndexForward' => true,
                ];

                if ($lastEvaluatedKey !== null) {
                    $params['ExclusiveStartKey'] = $lastEvaluatedKey;
                }

                $result = $this->dynamoDbClient->query($params);
                $items = $result->get('Items') ?? [];

                foreach ($items as $item) {
                    yield $this->mapItemToStreamEvent($item);
                }

                $lastEvaluatedKey = $result->get('LastEvaluatedKey');
            } while ($lastEvaluatedKey !== null);
        } catch (DynamoDbException $e) {
            throw UnableToRetrieveMessages::dueTo($e->getMessage(), $e);
        } catch (\Exception $e) {
            throw UnableToRetrieveMessages::dueTo($e->getMessage(), $e);
        }
    }

    /**
     * @return Generator<StoredEvent>
     * @throws UnableToRetrieveMessages
     */
    public function retrieveAllInStreamSinceCheckpoint(StreamName $streamName, int $position): \Generator
    {
        try {
            $streamNameString = $streamName->toString();
            $lastEvaluatedKey = null;

            do {
                $params = [
                    'TableName' => $this->eventStoreTableName,
                    'KeyConditionExpression' => 'stream_name = :stream_name AND stream_position > :position',
                    'ExpressionAttributeValues' => [
                        ':stream_name' => ['S' => $streamNameString],
                        ':position' => ['N' => (string) $position],
                    ],
                    'ScanIndexForward' => true,
                ];

                if ($lastEvaluatedKey !== null) {
                    $params['ExclusiveStartKey'] = $lastEvaluatedKey;
                }

                $result = $this->dynamoDbClient->query($params);
                $items = $result->get('Items') ?? [];

                foreach ($items as $item) {
                    yield $this->mapItemToStoredEvent($item);
                }

                $lastEvaluatedKey = $result->get('LastEvaluatedKey');
            } while ($lastEvaluatedKey !== null);
        } catch (DynamoDbException $e) {
            throw UnableToRetrieveMessages::dueTo($e->getMessage(), $e);
        } catch (\Exception $e) {
            throw UnableToRetrieveMessages::dueTo($e->getMessage(), $e);
        }
    }

    public function maxStreamPosition(StreamName $streamName): int
    {
        try {
            $streamNameString = $streamName->toString();

            $result = $this->dynamoDbClient->query([
                'TableName' => $this->eventStoreTableName,
                'KeyConditionExpression' => 'stream_name = :stream_name',
                'ExpressionAttributeValues' => [
                    ':stream_name' => ['S' => $streamNameString],
                ],
                'ScanIndexForward' => false,
                'Limit' => 1,
            ]);

            $items = $result->get('Items');
            if (empty($items)) {
                return 0;
            }

            $item = $items[0];
            return (int) $item['stream_position']['N'];
        } catch (DynamoDbException $e) {
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @throws DynamoDbException
     */
    private function putSingleEvent(
        StreamEvent $event,
        string $streamName,
        string $streamNameType,
        string $streamType,
    ): void {
        $serializationResult = $this->eventSerializer->serialize($event->payload);

        $item = [
            'stream_name' => ['S' => $streamName],
            'stream_position' => ['N' => (string) $event->position],
            'message_id' => ['S' => $event->eventId],
            'message_type' => ['S' => $serializationResult->eventType],
            'stream_name_type' => ['S' => $streamNameType],
            'stream_type' => ['S' => $streamType],
            'payload' => ['S' => $serializationResult->payload],
            'metadata' => ['S' => json_encode($event->metadata)],
            'created_at' => ['S' => (new DateTimeImmutable())->format('c')],
        ];

        // Conditional expression for optimistic locking
        $conditionExpression = 'attribute_not_exists(stream_position)';

        $this->dynamoDbClient->putItem([
            'TableName' => $this->eventStoreTableName,
            'Item' => $item,
            'ConditionExpression' => $conditionExpression,
        ]);
    }

    /**
     * @param StreamEvent[] $events
     * @throws DynamoDbException
     */
    private function transactionalWriteEvents(
        array $events,
        string $streamName,
        string $streamNameType,
        string $streamType,
    ): void {
        $transactionItems = [];
        $currentTime = new DateTimeImmutable();

        foreach ($events as $event) {
            $serializationResult = $this->eventSerializer->serialize($event->payload);

            $item = [
                'stream_name' => ['S' => $streamName],
                'stream_position' => ['N' => (string) $event->position],
                'message_id' => ['S' => $event->eventId],
                'message_type' => ['S' => $serializationResult->eventType],
                'stream_name_type' => ['S' => $streamNameType],
                'stream_type' => ['S' => $streamType],
                'payload' => ['S' => $serializationResult->payload],
                'metadata' => ['S' => json_encode($event->metadata)],
                'created_at' => ['S' => $currentTime->format('c')],
            ];

            // TransactWriteItems supports up to 100 items with conditional expressions
            $transactionItems[] = [
                'Put' => [
                    'TableName' => $this->eventStoreTableName,
                    'Item' => $item,
                    'ConditionExpression' => 'attribute_not_exists(stream_position)',
                ],
            ];

            // TransactWriteItems has a limit of 100 items per transaction
            if (count($transactionItems) === 100) {
                $this->executeTransactWrite($transactionItems);
                $transactionItems = [];
            }
        }

        // Write remaining items
        if (!empty($transactionItems)) {
            $this->executeTransactWrite($transactionItems);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $transactionItems
     * @throws DynamoDbException
     */
    private function executeTransactWrite(array $transactionItems): void
    {
        $this->dynamoDbClient->transactWriteItems([
            'TransactItems' => $transactionItems,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $item
     */
    private function mapItemToStreamEvent(array $item): StreamEvent
    {
        $serializationResult = new SerializationResult(
            eventType: $item['message_type']['S'],
            payload: $item['payload']['S'],
        );

        return new StreamEvent(
            eventId: $item['message_id']['S'],
            payload: $this->eventSerializer->deserialize($serializationResult),
            metadata: json_decode($item['metadata']['S'], true, 512, JSON_THROW_ON_ERROR),
            position: (int) $item['stream_position']['N'],
        );
    }

    /**
     * @param array<string, array<string, mixed>> $item
     */
    private function mapItemToStoredEvent(array $item): StoredEvent
    {
        return new StoredEvent(
            eventId: $item['message_id']['S'],
            eventType: $item['message_type']['S'],
            streamNameType: $item['stream_name_type']['S'],
            streamType: $item['stream_type']['S'],
            streamName: $item['stream_name']['S'],
            payloadJson: $item['payload']['S'],
            metadataJson: $item['metadata']['S'],
            streamPosition: (int) $item['stream_position']['N'],
            globalPosition: null,
            createdAt: new DateTimeImmutable($item['created_at']['S']),
        );
    }

    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        return $this->allStreamReader->paginate($streamQuery);
    }

    public function maxEventId(): int
    {
        return $this->allStreamReader->maxEventId();
    }

    public function getForEventId(string $messageId): StoredEvent
    {
        return $this->allStreamReader->getForEventId($messageId);
    }
}
