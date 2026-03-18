<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Infra;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;

final readonly class DynamoDbRunningProcesses implements RunningProcesses
{
    public function __construct(
        private DynamoDbClient $client,
        private string $tableName,
    ) {}

    public function start(string $subscriptionId, string $processId, \DateTime $expiresAt, bool $ignorePaused = false): void
    {
        // Allow a 30-second grace period before treating an expired process as stale,
        // to account for clock skew and in-flight operations completing.
        $now = (new \DateTime('now'))->sub(new \DateInterval('PT30S'))->format('c');
        $existing = $this->getProcessItem($subscriptionId);
        if ($existing !== null && ($existing['expires_at']['S'] ?? '') < $now) {
            $this->deleteProcessAndLookup($subscriptionId, $existing['process_id']['S'] ?? '');
            $existing = null;
        }

        if (!$ignorePaused && $this->isPaused($subscriptionId)) {
            $pauseItem = $this->getPauseItem($subscriptionId);
            throw StartProcessException::subscriptionIsPaused($pauseItem['reason']['S'] ?? null);
        }

        try {
            $this->client->putItem([
                'TableName' => $this->tableName,
                'Item' => [
                    'pk' => ['S' => "PROCESS#{$subscriptionId}"],
                    'subscription_id' => ['S' => $subscriptionId],
                    'process_id' => ['S' => $processId],
                    'expires_at' => ['S' => $expiresAt->format('c')],
                ],
                'ConditionExpression' => 'attribute_not_exists(pk)',
            ]);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                throw StartProcessException::cannotGetLockForProcess();
            }
            throw $e;
        }

        // Store reverse lookup: processId -> subscriptionId
        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => [
                'pk' => ['S' => "PROCESSID#{$processId}"],
                'subscription_id' => ['S' => $subscriptionId],
                'process_id' => ['S' => $processId],
            ],
        ]);
    }

    public function isActive(string $subscriptionId, ?string $processId = null): bool
    {
        if ($this->isPaused($subscriptionId)) {
            return false;
        }

        $item = $this->getProcessItem($subscriptionId);
        if ($item === null) {
            return false;
        }

        if ($processId !== null && ($item['process_id']['S'] ?? '') !== $processId) {
            return false;
        }

        $expiresAt = new \DateTime($item['expires_at']['S'] ?? 'now');

        return $expiresAt > new \DateTime('now');
    }

    public function timeLeft(string $processId): int
    {
        $subscriptionId = $this->resolveSubscriptionId($processId);
        if ($subscriptionId === null) {
            return 0;
        }

        if ($this->isPaused($subscriptionId)) {
            return 0;
        }

        $item = $this->getProcessItem($subscriptionId);
        if ($item === null) {
            return 0;
        }

        $expiresAt = new \DateTime($item['expires_at']['S'] ?? 'now');

        return max(0, $expiresAt->getTimestamp() - time());
    }

    public function stop(string $processId): void
    {
        $subscriptionId = $this->resolveSubscriptionId($processId);
        if ($subscriptionId !== null) {
            $this->deleteProcessAndLookup($subscriptionId, $processId);
        }
    }

    public function pause(string $subscriptionId, ?string $reason = null): void
    {
        $item = [
            'pk' => ['S' => "PAUSE#{$subscriptionId}"],
            'subscription_id' => ['S' => $subscriptionId],
        ];
        if ($reason !== null) {
            $item['reason'] = ['S' => $reason];
        }

        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => $item,
        ]);
    }

    public function resume(string $subscriptionId): void
    {
        $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PAUSE#{$subscriptionId}"]],
        ]);
    }

    /**
     * @return array<RunningProcess>
     */
    public function all(): array
    {
        /** @var array<string, string|null> $paused */
        $paused = [];
        /** @var array<string, array<string, array<string, string>>> $processes */
        $processes = [];
        $params = ['TableName' => $this->tableName];

        do {
            $scanResult = $this->client->scan($params);
            /** @var array<array<string, array<string, string>>> $items */
            $items = $scanResult['Items'] ?? [];
            foreach ($items as $item) {
                $pk = $item['pk']['S'];
                if (str_starts_with($pk, 'PAUSE#')) {
                    $paused[$item['subscription_id']['S']] = $item['reason']['S'] ?? null;
                } elseif (str_starts_with($pk, 'PROCESS#')) {
                    $processes[$item['subscription_id']['S']] = $item;
                }
                // Skip PROCESSID# lookup entries
            }
            /** @var array<string, array<string, string>>|null $lastKey */
            $lastKey = $scanResult['LastEvaluatedKey'] ?? null;
            $params['ExclusiveStartKey'] = $lastKey;
        } while ($params['ExclusiveStartKey'] !== null);

        $result = [];
        foreach ($processes as $subscriptionId => $item) {
            $result[] = new RunningProcess(
                subscriptionId: $subscriptionId,
                processId: $item['process_id']['S'],
                expiresAt: new \DateTime($item['expires_at']['S']),
                paused: isset($paused[$subscriptionId]),
                pausedReason: $paused[$subscriptionId] ?? null,
                status: $item['status']['S'] ?? null,
                lastStatusAt: isset($item['last_status_change_at']) ? new \DateTime($item['last_status_change_at']['S']) : null,
            );
        }

        foreach ($paused as $subscriptionId => $reason) {
            if (!isset($processes[$subscriptionId])) {
                $result[] = new RunningProcess(
                    subscriptionId: $subscriptionId,
                    processId: 'paused',
                    expiresAt: new \DateTime('now'),
                    paused: true,
                    pausedReason: $reason,
                    status: 'paused',
                    lastStatusAt: null,
                );
            }
        }

        return $result;
    }

    public function reportStatus(string $processId, string $status): void
    {
        $subscriptionId = $this->resolveSubscriptionId($processId);
        if ($subscriptionId === null) {
            return;
        }

        $this->client->updateItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PROCESS#{$subscriptionId}"]],
            'UpdateExpression' => 'SET #status = :status, last_status_change_at = :ts',
            'ExpressionAttributeNames' => ['#status' => 'status'],
            'ExpressionAttributeValues' => [
                ':status' => ['S' => $status],
                ':ts' => ['S' => (new \DateTime('now'))->format('c')],
            ],
        ]);
    }

    public function isPaused(string $subscriptionId): bool
    {
        return $this->getPauseItem($subscriptionId) !== null;
    }

    /**
     * Resolve subscriptionId from processId via the reverse lookup item.
     */
    private function resolveSubscriptionId(string $processId): ?string
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PROCESSID#{$processId}"]],
        ]);

        /** @var array<string, array<string, string>>|null $item */
        $item = $result['Item'] ?? null;

        return $item['subscription_id']['S'] ?? null;
    }

    private function deleteProcessAndLookup(string $subscriptionId, string $processId): void
    {
        $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PROCESS#{$subscriptionId}"]],
        ]);
        $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PROCESSID#{$processId}"]],
        ]);
    }

    /**
     * @return array<string, array<string, string>>|null
     */
    private function getProcessItem(string $subscriptionId): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PROCESS#{$subscriptionId}"]],
        ]);

        /** @var array<string, array<string, string>>|null $item */
        $item = $result['Item'] ?? null;

        return $item;
    }

    /**
     * @return array<string, array<string, string>>|null
     */
    private function getPauseItem(string $subscriptionId): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => "PAUSE#{$subscriptionId}"]],
        ]);

        /** @var array<string, array<string, string>>|null $item */
        $item = $result['Item'] ?? null;

        return $item;
    }
}
