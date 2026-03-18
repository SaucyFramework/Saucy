<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Checkpoints;

use Aws\DynamoDb\DynamoDbClient;

final readonly class DynamoDbCheckpointStore implements CheckpointStore
{
    public function __construct(
        private DynamoDbClient $client,
        private string $tableName,
    ) {}

    public function get(string $streamIdentifier): Checkpoint
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => [
                'stream_identifier' => ['S' => $streamIdentifier],
            ],
        ]);

        /** @var array<string, array<string, string>>|null $item */
        $item = $result['Item'] ?? null;
        if ($item === null) {
            throw CheckpointNotFound::forStream($streamIdentifier);
        }

        return new Checkpoint(
            streamIdentifier: $streamIdentifier,
            position: (int) ($item['position']['N'] ?? '0'),
        );
    }

    public function store(Checkpoint $checkpoint): void
    {
        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => [
                'stream_identifier' => ['S' => $checkpoint->streamIdentifier],
                'position' => ['N' => (string) $checkpoint->position],
            ],
        ]);
    }

    public function delete(string $streamIdentifier): void
    {
        $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => [
                'stream_identifier' => ['S' => $streamIdentifier],
            ],
        ]);
    }

    /**
     * @return array<Checkpoint>
     */
    public function getAll(): array
    {
        $checkpoints = [];
        $params = ['TableName' => $this->tableName];

        do {
            $result = $this->client->scan($params);
            /** @var array<array<string, array<string, string>>> $items */
            $items = $result['Items'] ?? [];
            foreach ($items as $item) {
                $checkpoints[] = new Checkpoint(
                    streamIdentifier: $item['stream_identifier']['S'],
                    position: (int) $item['position']['N'],
                );
            }
            /** @var array<string, array<string, string>>|null $lastKey */
            $lastKey = $result['LastEvaluatedKey'] ?? null;
            $params['ExclusiveStartKey'] = $lastKey;
        } while ($params['ExclusiveStartKey'] !== null);

        return $checkpoints;
    }
}
