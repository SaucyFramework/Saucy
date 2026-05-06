<?php

declare(strict_types=1);

namespace Saucy\Core\Framework\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;

final readonly class DynamoDbTableManager
{
    public function __construct(
        private DynamoDbClient $client,
        private string $prefix = '',
    ) {}

    public function checkpointTableName(): string
    {
        return $this->prefix . 'saucy_checkpoints';
    }

    public function processesTableName(): string
    {
        return $this->prefix . 'saucy_processes';
    }

    public function gapsTableName(): string
    {
        return $this->prefix . 'saucy_subscription_gaps';
    }

    /**
     * Create all Saucy DynamoDB tables. Safe to call multiple times — skips existing tables.
     */
    public function createTables(): void
    {
        $this->createCheckpointTable();
        $this->createProcessesTable();
        $this->createGapsTable();
    }

    public function createGapsTable(): void
    {
        $tableName = $this->gapsTableName();

        if ($this->tableExists($tableName)) {
            return;
        }

        $this->client->createTable([
            'TableName' => $tableName,
            'KeySchema' => [
                ['AttributeName' => 'subscription_id', 'KeyType' => 'HASH'],
                ['AttributeName' => 'position', 'KeyType' => 'RANGE'],
            ],
            'AttributeDefinitions' => [
                ['AttributeName' => 'subscription_id', 'AttributeType' => 'S'],
                ['AttributeName' => 'position', 'AttributeType' => 'N'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $this->client->waitUntil('TableExists', ['TableName' => $tableName]);
    }

    public function createCheckpointTable(): void
    {
        $tableName = $this->checkpointTableName();

        if ($this->tableExists($tableName)) {
            return;
        }

        $this->client->createTable([
            'TableName' => $tableName,
            'KeySchema' => [
                ['AttributeName' => 'stream_identifier', 'KeyType' => 'HASH'],
            ],
            'AttributeDefinitions' => [
                ['AttributeName' => 'stream_identifier', 'AttributeType' => 'S'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $this->client->waitUntil('TableExists', ['TableName' => $tableName]);
    }

    public function createProcessesTable(): void
    {
        $tableName = $this->processesTableName();

        if ($this->tableExists($tableName)) {
            return;
        }

        $this->client->createTable([
            'TableName' => $tableName,
            'KeySchema' => [
                ['AttributeName' => 'pk', 'KeyType' => 'HASH'],
            ],
            'AttributeDefinitions' => [
                ['AttributeName' => 'pk', 'AttributeType' => 'S'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $this->client->waitUntil('TableExists', ['TableName' => $tableName]);
    }

    public function deleteTables(): void
    {
        $this->deleteTableIfExists($this->checkpointTableName());
        $this->deleteTableIfExists($this->processesTableName());
        $this->deleteTableIfExists($this->gapsTableName());
    }

    private function tableExists(string $tableName): bool
    {
        try {
            $this->client->describeTable(['TableName' => $tableName]);

            return true;
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return false;
            }
            throw $e;
        }
    }

    private function deleteTableIfExists(string $tableName): void
    {
        try {
            $this->client->deleteTable(['TableName' => $tableName]);
            $this->client->waitUntil('TableNotExists', ['TableName' => $tableName]);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
    }
}
