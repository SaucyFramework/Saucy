<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;

/**
 * WorkerCoordination in Saucy's DynamoDB processes table, next to the locks.
 *
 * Two items, so a worker reads everything it needs with two GetItems and no Scan:
 *   - `WORKER#MEMBERS`: a `workers` map, worker id => {sha, expires_at}. Each worker updates only
 *     its own entry, so concurrent heartbeats do not overwrite each other.
 *   - `WORKER#GATE`: {sha, paused, paused_at, paused_at_position}.
 *   - `WORKER#RUNNERS`: a `runners` map, subscription id => `queue` | `worker`.
 * DynamoDbRunningProcesses::all() only reads `PROCESS#` and `PAUSE#` items, so the dashboard
 * ignores both.
 */
final readonly class DynamoDbWorkerCoordination implements WorkerCoordination
{
    private const MEMBERS = 'WORKER#MEMBERS';
    private const GATE = 'WORKER#GATE';
    private const RUNNERS = 'WORKER#RUNNERS';

    /** A dead worker's entry is removed once it has been expired this long. */
    private const PRUNE_AFTER_SECONDS = 300;

    public function __construct(
        private DynamoDbClient $client,
        private string $tableName,
    ) {}

    public function heartbeat(WorkerInfo $worker): void
    {
        $entry = ['M' => [
            'sha' => $worker->sha === null ? ['NULL' => true] : ['S' => $worker->sha],
            'expires_at' => ['N' => (string) $worker->expiresAt],
        ]];

        try {
            $this->setMember($worker->id, $entry);
        } catch (DynamoDbException $e) {
            // The first heartbeat ever: the `workers` map does not exist yet, so the nested path is invalid.
            if ($e->getAwsErrorCode() !== 'ValidationException') {
                throw $e;
            }
            $this->client->updateItem([
                'TableName' => $this->tableName,
                'Key' => ['pk' => ['S' => self::MEMBERS]],
                'UpdateExpression' => 'SET workers = if_not_exists(workers, :empty)',
                'ExpressionAttributeValues' => [':empty' => ['M' => []]],
            ]);
            $this->setMember($worker->id, $entry);
        }
    }

    public function liveWorkers(int $now): array
    {
        $members = self::value($this->item(self::MEMBERS), 'workers', 'M');

        $live = [];
        $dead = [];
        foreach (is_array($members) ? $members : [] as $id => $member) {
            $fields = is_array($member) && is_array($member['M'] ?? null) ? $member['M'] : [];
            $expiresAt = self::int(self::value($fields, 'expires_at', 'N')) ?? 0;
            if ($expiresAt > $now) {
                $sha = self::value($fields, 'sha', 'S');
                $live[] = new WorkerInfo(
                    id: (string) $id,
                    sha: is_string($sha) ? $sha : null,
                    expiresAt: $expiresAt,
                );
            } elseif ($expiresAt < $now - self::PRUNE_AFTER_SECONDS) {
                $dead[] = (string) $id;
            }
        }

        if ($dead !== []) {
            $this->removeMembers($dead);
        }

        return $live;
    }

    public function leave(string $workerId): void
    {
        $this->removeMembers([$workerId]);
    }

    public function gate(): ?DeployGate
    {
        $item = $this->item(self::GATE);
        $sha = self::value($item, 'sha', 'S');
        if (!is_string($sha)) {
            return null;
        }

        return new DeployGate(
            sha: $sha,
            paused: self::value($item, 'paused', 'BOOL') === true,
            pausedAt: self::int(self::value($item, 'paused_at_position', 'N')),
            pausedAtTime: self::int(self::value($item, 'paused_at', 'N')),
        );
    }

    public function pauseForDeploy(string $sha, ?int $head = null): void
    {
        $item = [
            'pk' => ['S' => self::GATE],
            'sha' => ['S' => $sha],
            'paused' => ['BOOL' => true],
            'paused_at' => ['N' => (string) time()],
            'updated_at' => ['S' => (new \DateTimeImmutable())->format('c')],
        ];
        if ($head !== null) {
            $item['paused_at_position'] = ['N' => (string) $head];
        }

        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => $item,
        ]);
    }

    public function liftPause(string $sha): bool
    {
        try {
            $this->client->updateItem([
                'TableName' => $this->tableName,
                'Key' => ['pk' => ['S' => self::GATE]],
                'UpdateExpression' => 'SET paused = :false, updated_at = :now',
                'ConditionExpression' => 'sha = :sha AND paused = :true',
                'ExpressionAttributeValues' => [
                    ':sha' => ['S' => $sha],
                    ':true' => ['BOOL' => true],
                    ':false' => ['BOOL' => false],
                    ':now' => ['S' => (new \DateTimeImmutable())->format('c')],
                ],
            ]);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function clearGate(): void
    {
        $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => self::GATE]],
        ]);
    }

    public function runnerOverrides(): array
    {
        $runners = self::value($this->item(self::RUNNERS), 'runners', 'M');

        $overrides = [];
        foreach (is_array($runners) ? $runners : [] as $subscriptionId => $runner) {
            if (is_array($runner) && is_string($runner['S'] ?? null)) {
                $overrides[(string) $subscriptionId] = $runner['S'];
            }
        }

        return $overrides;
    }

    public function setRunnerOverride(string $subscriptionId, ?string $runner): void
    {
        $update = $runner === null
            ? ['UpdateExpression' => 'REMOVE runners.#id']
            : ['UpdateExpression' => 'SET runners.#id = :runner', 'ExpressionAttributeValues' => [':runner' => ['S' => $runner]]];
        $request = [
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => self::RUNNERS]],
            'ExpressionAttributeNames' => ['#id' => $subscriptionId],
        ] + $update;

        try {
            $this->client->updateItem($request);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ValidationException') {
                throw $e;
            }
            if ($runner === null) {
                return; // the map was never created, so there is nothing to remove
            }
            $this->client->updateItem([
                'TableName' => $this->tableName,
                'Key' => ['pk' => ['S' => self::RUNNERS]],
                'UpdateExpression' => 'SET runners = if_not_exists(runners, :empty)',
                'ExpressionAttributeValues' => [':empty' => ['M' => []]],
            ]);
            $this->client->updateItem($request);
        }
    }

    /**
     * @param array{M: array<string, array<string, mixed>>} $entry
     */
    private function setMember(string $workerId, array $entry): void
    {
        $this->client->updateItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => self::MEMBERS]],
            'UpdateExpression' => 'SET workers.#id = :entry',
            'ExpressionAttributeNames' => ['#id' => $workerId],
            'ExpressionAttributeValues' => [':entry' => $entry],
        ]);
    }

    /**
     * @param list<string> $workerIds
     */
    private function removeMembers(array $workerIds): void
    {
        $names = [];
        foreach ($workerIds as $index => $workerId) {
            $names["#w{$index}"] = $workerId;
        }

        try {
            $this->client->updateItem([
                'TableName' => $this->tableName,
                'Key' => ['pk' => ['S' => self::MEMBERS]],
                'UpdateExpression' => 'REMOVE ' . implode(', ', array_map(fn(string $name) => "workers.{$name}", array_keys($names))),
                'ExpressionAttributeNames' => $names,
            ]);
        } catch (DynamoDbException $e) {
            // Nothing to remove when the `workers` map was never created.
            if ($e->getAwsErrorCode() !== 'ValidationException') {
                throw $e;
            }
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function item(string $pk): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => ['pk' => ['S' => $pk]],
            'ConsistentRead' => true,
        ]);
        $item = $result['Item'] ?? null;

        return is_array($item) ? $item : null;
    }

    /**
     * `$item[$name][$type]`, the DynamoDB attribute value of the given type.
     *
     * @param array<mixed>|null $item
     */
    private static function value(?array $item, string $name, string $type): mixed
    {
        $attribute = $item[$name] ?? null;

        return is_array($attribute) ? ($attribute[$type] ?? null) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
