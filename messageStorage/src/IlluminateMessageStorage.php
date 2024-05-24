<?php

namespace Saucy\MessageStorage;

use DateTimeImmutable;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\LazyCollection;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;

final readonly class IlluminateMessageStorage implements AllStreamMessageRepository, AllStreamReader, StreamReader
{
    public function __construct(
        private ConnectionInterface $connection,
        private EventSerializer $eventSerializer,
        private TypeMap $streamNameTypeMap,
        private string $tableName = 'event_store',
    ) {}

    public function persist(StreamName $streamName, StreamEvent ...$events): void
    {
        $streamNameType = $this->streamNameTypeMap->instanceToType($streamName);
        $streamType = $streamName->type();
        $streamName = $streamName->toString();
        $this->connection->table($this->tableName)->insert(
            array_map(
                function (StreamEvent $event) use ($streamName, $streamNameType, $streamType) {
                    $serializationResult = $this->eventSerializer->serialize($event->payload);
                    return [
                        'message_id' => $event->eventId,
                        'message_type' => $serializationResult->eventType,
                        'stream_name_type' => $streamNameType,
                        'stream_type' => $streamType,
                        'stream_name' => $streamName,
                        'stream_position' => $event->position,
                        'payload' => $serializationResult->payload,
                        'metadata' => json_encode($event->metadata), // move this to serializer as well?
                        'created_at' => now(),
                    ];
                },
                $events
            )
        );
    }

    /**
     * @param StreamName $streamName
     * @return Generator<StreamEvent>
     */
    public function retrieveAllInStream(StreamName $streamName): Generator
    {
        return $this->mapRowsToEvents($this->connection->table($this->tableName)
            ->where('stream_name', $streamName->toString())
            ->orderBy('stream_position')
            ->cursor());
    }

    /**
     * @return Generator<int, StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        return $this->mapRowsToStoredEvents(
            $this->connection->table($this->tableName)
            ->where('global_position', '>', $streamQuery->fromPosition)
            ->when($streamQuery->eventTypes !== null, function ($query) use ($streamQuery) {
                return $query->whereIn('message_type', $streamQuery->eventTypes);
            })
            ->limit($streamQuery->limit)
            ->orderBy('global_position')
            ->cursor()
        );
    }

    /**
     * @param LazyCollection<int, object> $rows
     * @return Generator
     */
    private function mapRowsToEvents(LazyCollection $rows): Generator
    {
        foreach ($rows as $row) {
            yield new StreamEvent(
                eventId: $row->message_id, // @phpstan-ignore-line
                payload: $this->eventSerializer->deserialize(
                    new SerializationResult(
                        eventType: $row->message_type, // @phpstan-ignore-line
                        payload: $row->payload, // @phpstan-ignore-line
                    )
                ),
                metadata: json_decode($row->metadata, true), // @phpstan-ignore-line
                position: $row->stream_position, // @phpstan-ignore-line
            );
        }
    }

    /**
     * @param LazyCollection<int, object> $rows
     * @return Generator
     * @throws \Exception
     */
    private function mapRowsToStoredEvents(LazyCollection $rows): Generator
    {
        foreach ($rows as $row) {
            yield new StoredEvent(
                eventId: $row->message_id, // @phpstan-ignore-line
                eventType: $row->message_type, // @phpstan-ignore-line
                streamNameType: $row->stream_name_type, // @phpstan-ignore-line
                streamType: $row->stream_type, // @phpstan-ignore-line
                streamName: $row->stream_name, // @phpstan-ignore-line
                payloadJson: $row->payload, // @phpstan-ignore-line
                metadataJson: $row->metadata, // @phpstan-ignore-line
                streamPosition: $row->stream_position, // @phpstan-ignore-line
                globalPosition: $row->global_position, // @phpstan-ignore-line
                createdAt: new DateTimeImmutable($row->created_at), // @phpstan-ignore-line
            );
        }
    }

    /**
     * @param StreamName $streamName
     * @param int $position
     * @return Generator<StoredEvent>
     */
    public function retrieveAllInStreamSinceCheckpoint(StreamName $streamName, int $position): Generator
    {
        return $this->mapRowsToStoredEvents(
            $this->connection->table($this->tableName)
                ->where('stream_name', $streamName->toString())
                ->where('stream_position', '>', $position)
                ->orderBy('stream_position')
                ->cursor()
        );
    }
}
