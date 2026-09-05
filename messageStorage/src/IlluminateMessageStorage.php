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
    /**
     * How far above the last settled row {@see safeCeiling()} will look in a single call.
     *
     * Without a cap, a bulk import inside the grace window makes the contiguity count and the
     * hole probe span every imported row, on every poll of every subscription. With it the
     * reader simply advances in cap-sized steps until it has caught up with the import.
     */
    public const int SAFE_CEILING_SCAN_CAP = 10_000;

    public function __construct(
        private ConnectionInterface $connection,
        private EventSerializer $eventSerializer,
        private TypeMap $streamNameTypeMap,
        private string $tableName = 'event_store',
        /**
         * Upper bound on how far above the last settled row safeCeiling() will reason in one
         * call. See SAFE_CEILING_SCAN_CAP.
         */
        private int $safeCeilingScanCap = self::SAFE_CEILING_SCAN_CAP,
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
            ->when($streamQuery->upToPosition !== null, function ($query) use ($streamQuery) {
                return $query->where('global_position', '<=', $streamQuery->upToPosition);
            })
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
        $row = $this->connection->table($this->tableName)
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
        return $this->toPosition($this->connection->table($this->tableName)->max('global_position'));
    }

    /**
     * The highest global position that is safe to consume and checkpoint to.
     *
     * A `global_position` is reserved when the row is INSERTed but only becomes visible when its
     * transaction COMMITs, so position 3 can be readable while position 2 is still in flight.
     * Reading up to `max(global_position)` therefore skips position 2 forever.
     *
     * Holes are also PERMANENT and common: every optimistic-concurrency conflict burns an
     * auto-increment value InnoDB never reuses. So the guard has to tell a young hole (maybe in
     * flight) from an old one (abandoned), and must not stall on the latter.
     *
     * The naive answer - "stop just below the oldest young row" - is wrong. If T1 allocates 5 and
     * T2 allocates 6 and commits first, the lowest visible young position is 6, and consuming up
     * to 5 would consume nothing while claiming 5 is settled; worse, if 5 later commits it has
     * already been passed. So the ceiling is only raised to `head` when the region above the last
     * settled row is actually CONTIGUOUS, which is what the count check below establishes. When
     * it is not, the ceiling is the last position before the first hole.
     *
     * Every query is a bounded index/PK lookup: nothing scans the old part of the table.
     */
    public function safeCeiling(\DateTimeInterface $committedBefore): int
    {
        $head = $this->maxEventId();

        if ($head === 0) {
            return 0;
        }

        $cutoff = $committedBefore->format('Y-m-d H:i:s');

        // The lowest position among rows young enough that a sibling could still be in flight.
        // Covered by the `created_at` index, so this never touches the old part of the table.
        $firstYoung = $this->connection->table($this->tableName)
            ->where('created_at', '>', $cutoff)
            ->min('global_position');

        if ($firstYoung === null) {
            // Nothing is young: every visible row is settled and no hole can still be filled.
            return $head;
        }

        // The last OLD visible row. Everything at or below it is settled - a hole below it has a
        // settled row above it, so that hole is older than the grace window and abandoned.
        $lastOld = $this->connection->table($this->tableName)
            ->where('global_position', '<', $this->toPosition($firstYoung))
            ->max('global_position');

        $lastOld = $this->toPosition($lastOld);

        // Never reason about more than the cap in one call: a bulk import inside the grace
        // window would otherwise make both queries below span every imported row on every poll.
        // Returning the capped upper bound simply advances the reader in cap-sized steps.
        $upper = min($head, $lastOld + $this->safeCeilingScanCap);

        $expected = $upper - $lastOld;
        $actual = $this->connection->table($this->tableName)
            ->where('global_position', '>', $lastOld)
            ->where('global_position', '<=', $upper)
            ->count();

        // Equality is the real predicate - global positions are unique, so the count can never
        // exceed the size of the range. `>=` is defensive only.
        if ($actual >= $expected) {
            return $upper;
        }

        // There is a hole above the settled region. Pull the positions in the (capped) range and
        // walk to the first gap. Deliberately a plain query rather than a correlated
        // `NOT EXISTS ... global_position + 1` self-join: that needs table aliases, and Laravel
        // applies the connection's table prefix to aliases too, so the raw comparison inside it
        // silently breaks on a prefixed connection. The range is bounded by the scan cap, and
        // this only runs while a hole actually exists.
        $positions = $this->connection->table($this->tableName)
            ->where('global_position', '>', $lastOld)
            ->where('global_position', '<=', $upper)
            ->orderBy('global_position')
            ->pluck('global_position');

        $previous = $lastOld;
        foreach ($positions as $position) {
            if ($this->toPosition($position) !== $previous + 1) {
                // The hole sits between $previous and this row, so $previous is the ceiling.
                // When $previous is 0 (position 1 itself has not committed) nothing is safe yet.
                return max($previous, 0);
            }

            $previous = $this->toPosition($position);
        }

        return max($previous, 0);
    }

    private function toPosition(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function maxStreamPosition(StreamName $streamName): int
    {
        return $this->toPosition($this->connection->table($this->tableName)->where('stream_name', $streamName->toString())->max('stream_position'));
    }

    public function maxStreamPositionAtGlobalPosition(StreamName $streamName, int $globalPosition): int
    {
        return (int) ($this->connection->table($this->tableName)
            ->where('stream_name', $streamName->toString())
            ->where('global_position', '<=', $globalPosition)
            ->max('stream_position') ?? 0);
    }
}
