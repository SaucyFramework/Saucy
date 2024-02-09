<?php

namespace Saucy\Core\Subscriptions\Checkpoints;

use Illuminate\Database\ConnectionInterface;

final readonly class IlluminateCheckpointStore implements CheckpointStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'checkpoint_store',
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $streamIdentifier): Checkpoint
    {
        $row = $this->connection->table($this->tableName)
            ->where('stream_identifier', $streamIdentifier)
            ->first();

        if(!$row){
            CheckpointNotFound::forStream($streamIdentifier);
        }

        return new Checkpoint(
            streamIdentifier: $streamIdentifier,
            position: $row->position ?? 0,
        );
    }

    public function store(Checkpoint $checkpoint): void
    {
        $this->connection->table($this->tableName)
            ->updateOrInsert(
                ['stream_identifier' => $checkpoint->streamIdentifier],
                ['position' => $checkpoint->position],
            );
    }
}
