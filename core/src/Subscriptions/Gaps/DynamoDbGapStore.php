<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Gaps;

use Aws\DynamoDb\DynamoDbClient;
use DateTimeImmutable;

final readonly class DynamoDbGapStore implements GapStore
{
    public function __construct(
        private DynamoDbClient $client,
        private string $checkpointTable,
        private string $gapsTable,
    ) {}

    public function getOpen(string $subscriptionId): array
    {
        $gaps = [];
        $exclusiveStartKey = null;

        do {
            $params = [
                'TableName' => $this->gapsTable,
                'KeyConditionExpression' => 'subscription_id = :sid',
                'ExpressionAttributeValues' => [
                    ':sid' => ['S' => $subscriptionId],
                ],
            ];
            if ($exclusiveStartKey !== null) {
                $params['ExclusiveStartKey'] = $exclusiveStartKey;
            }

            $result = $this->client->query($params);

            /** @var array<array<string, array<string, string>>> $items */
            $items = $result['Items'] ?? [];
            foreach ($items as $item) {
                $gaps[] = new Gap(
                    position: (int) $item['position']['N'],
                    firstSeenAt: new DateTimeImmutable('@' . $item['first_seen_at']['N']),
                );
            }

            /** @var array<string, array<string, string>>|null $lastKey */
            $lastKey = $result['LastEvaluatedKey'] ?? null;
            $exclusiveStartKey = $lastKey;
        } while ($exclusiveStartKey !== null);

        return $gaps;
    }

    public function commit(
        string $subscriptionId,
        int $newCheckpointPosition,
        array $newGapPositions,
        array $closedGapPositions,
        DateTimeImmutable $now,
    ): void {
        $transactItems = [];

        $transactItems[] = [
            'Put' => [
                'TableName' => $this->checkpointTable,
                'Item' => [
                    'stream_identifier' => ['S' => $subscriptionId],
                    'position' => ['N' => (string) $newCheckpointPosition],
                ],
            ],
        ];

        $firstSeen = (string) $now->getTimestamp();
        foreach ($newGapPositions as $position) {
            $transactItems[] = [
                'Put' => [
                    'TableName' => $this->gapsTable,
                    'Item' => [
                        'subscription_id' => ['S' => $subscriptionId],
                        'position' => ['N' => (string) $position],
                        'first_seen_at' => ['N' => $firstSeen],
                    ],
                ],
            ];
        }

        foreach ($closedGapPositions as $position) {
            $transactItems[] = [
                'Delete' => [
                    'TableName' => $this->gapsTable,
                    'Key' => [
                        'subscription_id' => ['S' => $subscriptionId],
                        'position' => ['N' => (string) $position],
                    ],
                ],
            ];
        }

        // DynamoDB caps TransactWriteItems at 100 ops. Chunk if needed —
        // we lose strict atomicity across chunks, but each chunk is atomic
        // and we always order: checkpoint+new-gaps first, then closures.
        // A crash between chunks leaves either (a) the original state, or
        // (b) checkpoint+new-gaps written but some closures pending — which
        // is safe (closures are idempotent re-checks on next poll).
        if (count($transactItems) <= 100) {
            $this->client->transactWriteItems(['TransactItems' => $transactItems]);
            return;
        }

        $writeFirst = array_slice($transactItems, 0, 100);
        $this->client->transactWriteItems(['TransactItems' => $writeFirst]);

        $remaining = array_slice($transactItems, 100);
        foreach (array_chunk($remaining, 100) as $chunk) {
            $this->client->transactWriteItems(['TransactItems' => $chunk]);
        }
    }

    public function deleteAll(string $subscriptionId): void
    {
        foreach ($this->getOpen($subscriptionId) as $gap) {
            $this->client->deleteItem([
                'TableName' => $this->gapsTable,
                'Key' => [
                    'subscription_id' => ['S' => $subscriptionId],
                    'position' => ['N' => (string) $gap->position],
                ],
            ]);
        }
    }
}
