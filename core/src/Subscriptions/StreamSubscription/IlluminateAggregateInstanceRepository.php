<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Generator;
use Illuminate\Database\ConnectionInterface;

final readonly class IlluminateAggregateInstanceRepository implements AggregateInstanceRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'aggregate_instances',
    ) {}

    public function record(string $aggregateType, string $aggregateId, int $streamPosition): void
    {
        $this->connection->table($this->tableName)->upsert(
            [
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'stream_position' => $streamPosition,
            ],
            ['aggregate_type', 'aggregate_id'],
            ['stream_position'],
        );
    }

    /**
     * @return Generator<object{aggregateId: string, streamPosition: int}>
     */
    public function getAllForType(string $aggregateType): Generator
    {
        $cursor = $this->connection->table($this->tableName)
            ->where('aggregate_type', $aggregateType)
            ->orderBy('aggregate_id')
            ->cursor();

        foreach ($cursor as $row) {
            yield (object) [
                'aggregateId' => $row->aggregate_id,
                'streamPosition' => (int) $row->stream_position,
            ];
        }
    }
}
