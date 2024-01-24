<?php

namespace Saucy\MessageStorage;

use EventSauce\EventSourcing\Message;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

final readonly class IlluminateMessageStorage implements AllStreamMessageRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private EventSerializer $eventSerializer,
        private string $tableName = 'event_store',
    ) {

    }
    public function persist(StreamName $streamName, StreamEvent ...$events): void
    {
        $this->connection->table($this->tableName)->insert(
            array_map(
                function (StreamEvent $event) use ($streamName) {
                    $storedEvent = $this->eventSerializer->serialize($event);
                    return [
                        'message_id' => $storedEvent->eventId,
                        'message_type' => $storedEvent->eventType,
                        'stream_name' => $streamName->toString(),
                        'stream_position' => $storedEvent->position,
                        'payload' => $storedEvent->payloadJson,
                        'metadata' => $storedEvent->metadataJson,
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
        $rows = $this->connection->table($this->tableName)
            ->where('stream_name', $streamName->toString())
            ->orderBy('stream_position')
            ->cursor();

        return $this->mapRowsToEvents($rows);
    }

    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        throw new \Exception('Not implemented');
    }

    private function mapRowsToEvents($rows): Generator
    {
        foreach ($rows as $row) {
            yield $this->eventSerializer->deserialize(
                new StoredEvent(
                    eventId: $row->message_id,
                    eventType: $row->message_type,
                    payloadJson: $row->payload,
                    metadataJson: $row->metadata,
                    position: $row->stream_position,
                ));
        }
    }
}
