<?php

namespace Saucy\MessageStorage;

use DateTimeImmutable;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use App\Components\Api\EventData;

final readonly class IlluminateMessageStorage implements AllStreamMessageRepository, AllStreamReader, StreamReader, ReadEventData
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
                $events,
            ),
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
        // Resolve the matching global positions first, selecting only the indexed
        // `global_position` column. This keeps the `(message_type, global_position)`
        // index covering, so the `ORDER BY global_position ... LIMIT` is satisfied by a
        // narrow sort over 8-byte integers.
        //
        // Selecting whole rows here (the previous `SELECT *`) forces MySQL 8 to
        // filesort the full rows — including the large `payload`/`metadata` JSON
        // columns — because a `message_type IN (...)` filter spans multiple index
        // ranges that can no longer feed the `ORDER BY` directly. With wide JSON
        // payloads that sort can exhaust `sort_buffer_size` and fail with
        // ER_OUT_OF_SORTMEMORY (errno 1038), as seen on PlanetScale/Vitess. Fetching
        // the rows afterwards by primary key avoids putting the payload in the sort.
        $positions = $this->connection->table($this->tableName)
            ->where('global_position', '>', $streamQuery->fromPosition)
            ->when($streamQuery->eventTypes !== null, function ($query) use ($streamQuery) {
                return $query->whereIn('message_type', $streamQuery->eventTypes);
            })
            ->orderBy('global_position')
            ->limit($streamQuery->limit)
            ->pluck('global_position')
            ->all();

        if ($positions === []) {
            return;
        }

        yield from $this->mapRowsToStoredEvents(
            $this->connection->table($this->tableName)
                ->whereIn('global_position', $positions)
                ->orderBy('global_position')
                ->cursor(),
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
                    ),
                ),
                metadata: json_decode($row->metadata, true), // @phpstan-ignore-line
                position: $row->stream_position, // @phpstan-ignore-line
            );
        }
    }

    /**
     * @param LazyCollection<int, object> $rows
     * @return Generator<int, StoredEvent>
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
                ->cursor(),
        );
    }

    public function getForEventId(string $messageId): StoredEvent
    {
        $row = DB::table('event_store')
            ->where('message_id', $messageId)
            ->first();

        if ($row === null) {
            throw new \Exception("Event not found");
        }

        return new StoredEvent(
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

    public function maxEventId(): int
    {
        return DB::table($this->tableName)->max('global_position') ?? 0;
    }

    public function maxStreamPosition(StreamName $streamName): int
    {
        return DB::table($this->tableName)->where('stream_name', $streamName->toString())->max('stream_position') ?? 0;
    }

    public function maxStreamPositionAtGlobalPosition(StreamName $streamName, int $globalPosition): int
    {
        return (int) (DB::table($this->tableName)
            ->where('stream_name', $streamName->toString())
            ->where('global_position', '<=', $globalPosition)
            ->max('stream_position') ?? 0);
    }
}
