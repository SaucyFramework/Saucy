<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Metrics;

use Aws\DynamoDb\DynamoDbClient;

/**
 * DynamoDB-backed activity stream logger.
 *
 * Key design (the table is created by {@see \Saucy\Core\Framework\DynamoDb\DynamoDbTableManager}):
 *   - PK  `stream_id`  — partitions the log per subscription, the common read scope.
 *   - SK  `sk`         — `{occurred_at:Y-m-d\TH:i:s.u}#{random}`, time-sortable + unique,
 *                        so stream-scoped reads and time-range reads are native Queries.
 *   - GSI `gsi_date_index` — HASH `gsi_date` (Y-m-d bucket), RANGE `sk` — lets the global
 *                        (streamId === null) reads scan a bounded set of day buckets instead
 *                        of doing a full-table Scan.
 *   - `ttl`            — epoch expiry; DynamoDB TTL prunes old rows automatically, so
 *                        {@see purgeOld()} is a no-op. Retention comes from
 *                        config('saucy.activity_log_retention_days'), defaulting to 7 days.
 *
 * Reads honour the interface's limit/offset by over-reading and skipping; this is fine for the
 * small, operator-driven debug pages this log serves. Global reads walk day buckets newest-first
 * up to {@see MAX_LOOKBACK_DAYS}.
 */
final readonly class DynamoDbActivityStreamLogger implements ActivityStreamLogger
{
    private const MAX_BATCH = 25;          // DynamoDB BatchWriteItem hard limit
    private const MAX_LOOKBACK_DAYS = 92;  // cap for unbounded newest-first global reads

    public function __construct(
        private DynamoDbClient $client,
        private string $tableName,
        private string $gsiName = 'gsi_date_index',
    ) {}

    public function log(SubscriptionActivity ...$subscriptionActivity): void
    {
        if ($subscriptionActivity === []) {
            return;
        }

        $retentionDays = $this->retentionDays();

        foreach (array_chunk($subscriptionActivity, self::MAX_BATCH) as $chunk) {
            $requests = array_map(
                fn(SubscriptionActivity $activity): array => [
                    'PutRequest' => ['Item' => $this->toItem($activity, $retentionDays)],
                ],
                $chunk,
            );

            $this->batchWrite($requests);
        }
    }

    public function getLog(?string $streamId, int $limit = 100, int $offset = 0): array
    {
        if ($streamId !== null) {
            return $this->queryNewestFirst(
                [
                    'TableName' => $this->tableName,
                    'KeyConditionExpression' => 'stream_id = :sid',
                    'ExpressionAttributeValues' => [':sid' => ['S' => $streamId]],
                ],
                $limit,
                $offset,
            );
        }

        // No stream scope: walk day buckets newest-first until we have enough rows.
        $collected = [];
        $need = $limit + $offset;
        $day = new \DateTimeImmutable('today');

        for ($i = 0; $i < self::MAX_LOOKBACK_DAYS && count($collected) < $need; $i++) {
            $collected = array_merge($collected, $this->queryNewestFirst(
                [
                    'TableName' => $this->tableName,
                    'IndexName' => $this->gsiName,
                    'KeyConditionExpression' => 'gsi_date = :d',
                    'ExpressionAttributeValues' => [':d' => ['S' => $day->format('Y-m-d')]],
                ],
                $need,
                0,
            ));
            $day = $day->sub(new \DateInterval('P1D'));
        }

        return array_slice($collected, $offset, $limit);
    }

    public function getLogBetween(?string $streamId, \DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array
    {
        $low = $this->sortKeyLowerBound($from);
        $high = $this->sortKeyUpperBound($to);

        if ($streamId !== null) {
            return $this->queryNewestFirst(
                [
                    'TableName' => $this->tableName,
                    'KeyConditionExpression' => 'stream_id = :sid AND sk BETWEEN :from AND :to',
                    'ExpressionAttributeValues' => [
                        ':sid' => ['S' => $streamId],
                        ':from' => ['S' => $low],
                        ':to' => ['S' => $high],
                    ],
                ],
                $limit,
                $offset,
            );
        }

        // No stream scope: query each day bucket in the window, newest day first.
        $collected = [];
        $need = $limit + $offset;
        foreach ($this->daysDescending($from, $to) as $day) {
            if (count($collected) >= $need) {
                break;
            }
            $collected = array_merge($collected, $this->queryNewestFirst(
                [
                    'TableName' => $this->tableName,
                    'IndexName' => $this->gsiName,
                    'KeyConditionExpression' => 'gsi_date = :d AND sk BETWEEN :from AND :to',
                    'ExpressionAttributeValues' => [
                        ':d' => ['S' => $day],
                        ':from' => ['S' => $low],
                        ':to' => ['S' => $high],
                    ],
                ],
                $need,
                0,
            ));
        }

        return array_slice($collected, $offset, $limit);
    }

    /**
     * No-op: expiry is handled by the table's DynamoDB TTL on the `ttl` attribute, set on write.
     * Kept to satisfy the {@see ActivityStreamLogger} contract and stay a drop-in replacement.
     */
    public function purgeOld(?\DateTime $before = null): void {}

    /**
     * @param array<int, array{PutRequest: array{Item: array<string, array<string, string>>}}> $requests
     */
    private function batchWrite(array $requests): void
    {
        $unprocessed = [$this->tableName => $requests];

        // Logging must never stall polling: retry unprocessed items a few times, then drop them.
        for ($attempt = 0; $attempt < 3 && $unprocessed !== []; $attempt++) {
            $result = $this->client->batchWriteItem(['RequestItems' => $unprocessed]);
            /** @var array<string, array<int, mixed>> $unprocessed */
            $unprocessed = $result['UnprocessedItems'] ?? [];
        }
    }

    /**
     * Run a Query newest-first, skipping $offset matching items and collecting up to $limit,
     * paging through the result set as needed.
     *
     * @param array<string, mixed> $params
     * @return array<SubscriptionActivity>
     */
    private function queryNewestFirst(array $params, int $limit, int $offset): array
    {
        $params['ScanIndexForward'] = false;

        $skip = $offset;
        $collected = [];

        do {
            /** @var array{Items?: array<int, array<string, array<string, string>>>, LastEvaluatedKey?: array<string, array<string, string>>} $result */
            $result = $this->client->query($params);

            foreach ($result['Items'] ?? [] as $item) {
                if ($skip > 0) {
                    $skip--;
                    continue;
                }
                $collected[] = $this->toActivity($item);
                if (count($collected) >= $limit) {
                    return $collected;
                }
            }

            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
        } while ($params['ExclusiveStartKey'] !== null);

        return $collected;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function toItem(SubscriptionActivity $activity, int $retentionDays): array
    {
        $sortableTime = $activity->occurredAt->format('Y-m-d\TH:i:s.u');
        $ttl = (int) $activity->occurredAt->format('U') + ($retentionDays * 86400);

        return [
            'stream_id' => ['S' => $activity->streamId],
            'sk' => ['S' => $sortableTime . '#' . bin2hex(random_bytes(8))],
            'gsi_date' => ['S' => $activity->occurredAt->format('Y-m-d')],
            'type' => ['S' => $activity->type],
            'message' => ['S' => $activity->message],
            'occurred_at' => ['S' => $activity->occurredAt->format('Y-m-d H:i:s')],
            'data' => ['S' => json_encode($activity->data) ?: '[]'],
            'ttl' => ['N' => (string) $ttl],
        ];
    }

    /**
     * @param array<string, array<string, string>> $item
     */
    private function toActivity(array $item): SubscriptionActivity
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($item['data']['S'] ?? '[]', true) ?: [];

        return new SubscriptionActivity(
            $item['stream_id']['S'] ?? '',
            $item['type']['S'] ?? '',
            $item['message']['S'] ?? '',
            new \DateTime($item['occurred_at']['S'] ?? 'now'),
            $data,
        );
    }

    private function sortKeyLowerBound(\DateTime $from): string
    {
        // A bare timestamp sorts before any `timestamp#suffix`, making the lower bound inclusive.
        return $from->format('Y-m-d\TH:i:s.u');
    }

    private function sortKeyUpperBound(\DateTime $to): string
    {
        // '~' (0x7E) sorts above '#' and every hex suffix char, making the upper bound inclusive.
        return $to->format('Y-m-d\TH:i:s.u') . '#~';
    }

    /**
     * @return array<int, string> Y-m-d day buckets spanning [from, to], newest first.
     */
    private function daysDescending(\DateTime $from, \DateTime $to): array
    {
        $cursor = (new \DateTimeImmutable($to->format('Y-m-d')));
        $floor = (new \DateTimeImmutable($from->format('Y-m-d')));

        $days = [];
        while ($cursor >= $floor && count($days) < 366) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->sub(new \DateInterval('P1D'));
        }

        return $days;
    }

    private function retentionDays(): int
    {
        /** @var int|null $configured */
        $configured = config('saucy.activity_log_retention_days');

        return $configured ?? 7;
    }
}
