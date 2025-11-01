<?php

namespace Saucy\MessageStorage\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;

final readonly class DynamoDbTableManager
{
    public function __construct(
        private DynamoDbClient $dynamoDbClient,
    ) {}

    /**
     * Ensure the DynamoDB event store table exists with correct configuration.
     * Returns the Stream ARN if successful.
     *
     * @throws DynamoDbException
     * @throws \Exception
     */
    public function ensureEventStoreTable(string $tableName = 'event_store'): string
    {
        if ($this->tableExists($tableName)) {
            return $this->getStreamArn($tableName);
        }

        $this->createEventStoreTable($tableName);
        $this->waitForTableActive($tableName);

        return $this->getStreamArn($tableName);
    }

    public function tableExists(string $tableName): bool
    {
        try {
            $this->dynamoDbClient->describeTable([
                'TableName' => $tableName,
            ]);
            return true;
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @throws DynamoDbException
     */
    public function waitForTableActive(string $tableName): void
    {
        $waiter = $this->dynamoDbClient->getWaiter('TableExists', [
            'TableName' => $tableName,
        ]);

        $waiter->promise()->wait();
    }

    /**
     * @throws DynamoDbException
     */
    private function createEventStoreTable(string $tableName): void
    {
        $this->dynamoDbClient->createTable([
            'TableName' => $tableName,
            'KeySchema' => [
                [
                    'AttributeName' => 'stream_name',
                    'KeyType' => 'HASH',
                ],
                [
                    'AttributeName' => 'stream_position',
                    'KeyType' => 'RANGE',
                ],
            ],
            'AttributeDefinitions' => [
                [
                    'AttributeName' => 'stream_name',
                    'AttributeType' => 'S',
                ],
                [
                    'AttributeName' => 'stream_position',
                    'AttributeType' => 'N',
                ],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
            'StreamSpecification' => [
                'StreamEnabled' => true,
                'StreamViewType' => 'NEW_IMAGE',
            ],
        ]);
    }

    /**
     * @throws DynamoDbException
     * @throws \Exception
     */
    private function getStreamArn(string $tableName): string
    {
        $result = $this->dynamoDbClient->describeTable([
            'TableName' => $tableName,
        ]);

        $streamArn = $result->get('Table')['LatestStreamArn'] ?? null;

        if ($streamArn === null) {
            throw new \Exception("Stream ARN not found for table '{$tableName}'. Streams may not be enabled.");
        }

        return $streamArn;
    }
}

