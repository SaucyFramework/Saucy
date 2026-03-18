<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Checkpoints;

/**
 * Gradual migration from SQL to DynamoDB.
 * Reads from DynamoDB first. On miss, reads from SQL and writes to DynamoDB.
 * All writes go to DynamoDB.
 */
final readonly class MigratingCheckpointStore implements CheckpointStore
{
    public function __construct(
        private CheckpointStore $dynamoDb,
        private CheckpointStore $sql,
    ) {}

    public function get(string $streamIdentifier): Checkpoint
    {
        try {
            return $this->dynamoDb->get($streamIdentifier);
        } catch (CheckpointNotFound) {
            // Not in DynamoDB yet, try SQL
        }

        // Will throw CheckpointNotFound if not in SQL either
        $checkpoint = $this->sql->get($streamIdentifier);

        // Migrate to DynamoDB
        $this->dynamoDb->store($checkpoint);

        return $checkpoint;
    }

    public function store(Checkpoint $checkpoint): void
    {
        $this->dynamoDb->store($checkpoint);
    }

    public function delete(string $streamIdentifier): void
    {
        $this->dynamoDb->delete($streamIdentifier);
        $this->sql->delete($streamIdentifier);
    }

    /**
     * @return array<Checkpoint>
     */
    public function getAll(): array
    {
        // Return from DynamoDB — after full migration, this is the source of truth.
        // During migration, some checkpoints may still only be in SQL.
        // Merge both, DynamoDB takes precedence.
        $dynamoCheckpoints = $this->dynamoDb->getAll();
        $sqlCheckpoints = $this->sql->getAll();

        $merged = [];
        foreach ($dynamoCheckpoints as $checkpoint) {
            $merged[$checkpoint->streamIdentifier] = $checkpoint;
        }
        foreach ($sqlCheckpoints as $checkpoint) {
            if (!isset($merged[$checkpoint->streamIdentifier])) {
                $merged[$checkpoint->streamIdentifier] = $checkpoint;
            }
        }

        return array_values($merged);
    }
}
