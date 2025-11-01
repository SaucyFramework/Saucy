# DynamoDB Event Store

This package provides a DynamoDB-backed event store implementation for Saucy's event sourcing system. It offers high-throughput writes with optimistic locking and uses DynamoDB Streams for projection processing.

## Features

- **High Write Throughput**: ~1,000 writes/sec per aggregate root, scales linearly
- **Optimistic Locking**: Prevents concurrent writes to the same stream position
- **DynamoDB Streams**: Automatic change data capture for projection processing
- **Partition-Based Sharding**: ULID-based stream names ensure optimal distribution
- **On-Demand Billing**: PAY_PER_REQUEST mode with no throttling

## Setup

### 1. Install Dependencies

```bash
composer require aws/aws-sdk-php
```

### 2. Configure AWS SDK

Create a DynamoDB client:

```php
use Aws\DynamoDb\DynamoDbClient;

$dynamoDbClient = new DynamoDbClient([
    'region' => 'us-east-1',
    'version' => 'latest',
    // For local testing with DynamoDB Local:
    // 'endpoint' => 'http://localhost:8000',
]);
```

### 3. Create Tables

Run the Artisan command to create the event store table:

```bash
php artisan saucy:dynamodb:ensure-tables
```

Or programmatically:

```php
use Saucy\MessageStorage\DynamoDb\DynamoDbTableManager;

$tableManager = new DynamoDbTableManager($dynamoDbClient);
$streamArn = $tableManager->ensureEventStoreTable('event_store');
echo "Stream ARN: {$streamArn}";
```

### 4. Register Service

In your service provider:

```php
use Aws\DynamoDb\DynamoDbClient;
use Saucy\MessageStorage\DynamoDb\DynamoDbMessageStorage;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\Core\Serialisation\TypeMap;

$this->app->singleton(DynamoDbMessageStorage::class, function ($app) {
    return new DynamoDbMessageStorage(
        dynamoDbClient: app(DynamoDbClient::class),
        eventSerializer: app(EventSerializer::class),
        streamNameTypeMap: app(TypeMap::class),
        eventStoreTableName: 'event_store',
    );
});
```

## Table Schema

### Event Store Table

- **Partition Key**: `stream_name` (String) - ULID-based for optimal distribution
- **Sort Key**: `stream_position` (Number)
- **Attributes**:
  - `message_id` (String) - ULID
  - `message_type` (String)
  - `stream_name_type` (String)
  - `stream_type` (String)
  - `payload` (String) - JSON serialized
  - `metadata` (String) - JSON serialized
  - `created_at` (String) - ISO 8601 timestamp

- **Billing Mode**: PAY_PER_REQUEST (on-demand)
- **Streams**: Enabled with NEW_IMAGE view type

## DynamoDB Streams Consumer

DynamoDB Streams provides automatic change data capture for your events. Configure a consumer to process stream records and write to your MySQL all_stream table, then trigger projections.

### Lambda Consumer Pattern (Recommended)

Create a Lambda function with an event source mapping:

```yaml
# serverless.yml or similar
functions:
  processStream:
    handler: ProcessDynamoDbStream.handler
    events:
      - stream:
          type: dynamodb
          arn: arn:aws:dynamodb:region:account:table/event_store/stream/...
          batchSize: 100
          startingPosition: LATEST
```

Lambda function (Node.js example):

```javascript
exports.handler = async (event) => {
  for (const record of event.Records) {
    if (record.eventName === 'INSERT') {
      const eventData = record.dynamodb.NewImage;
      
      // Write to MySQL all_stream table
      await mysql.insert({
        message_id: eventData.message_id.S,
        message_type: eventData.message_type.S,
        payload: eventData.payload.S,
        // ... other fields
      });
      
      // Trigger projections
      await triggerProjections(eventData);
    }
  }
};
```

PHP Lambda function:

```php
<?php

require 'vendor/autoload.php';

use Aws\Lambda\LambdaClient;

function handler(array $event): void {
    $pdo = new PDO(/* MySQL connection */);
    
    foreach ($event['Records'] as $record) {
        if ($record['eventName'] === 'INSERT') {
            $item = $record['dynamodb']['NewImage'];
            
            // Insert into MySQL all_stream
            $stmt = $pdo->prepare("
                INSERT INTO event_store 
                (message_id, message_type, payload, ...) 
                VALUES (?, ?, ?, ...)
            ");
            $stmt->execute([
                $item['message_id']['S'],
                $item['message_type']['S'],
                $item['payload']['S'],
                // ...
            ]);
            
            // Trigger projections
            processProjections($item);
        }
    }
}
```

### AWS Kinesis Data Streams Pattern

For more complex processing pipelines:

1. Enable DynamoDB Streams
2. Configure Kinesis Data Streams as consumer
3. Process via Lambda, Kinesis Data Analytics, or Firehose
4. Write to MySQL and trigger projections

### Custom Consumer Pattern

Poll DynamoDB Streams manually:

```php
use Aws\DynamoDb\DynamoDbClient;

$client = new DynamoDbClient(['region' => 'us-east-1']);
$streamArn = 'arn:aws:dynamodb:...';

// Get shard iterator
$result = $client->getShardIterator([
    'StreamArn' => $streamArn,
    'ShardId' => $shardId,
    'ShardIteratorType' => 'LATEST',
]);

$shardIterator = $result['ShardIterator'];

// Poll records
while (true) {
    $records = $client->getRecords(['ShardIterator' => $shardIterator]);
    
    foreach ($records['Records'] as $record) {
        if ($record['eventName'] === 'INSERT') {
            processEvent($record['dynamodb']['NewImage']);
        }
    }
    
    $shardIterator = $records['NextShardIterator'];
    sleep(1); // Poll interval
}
```

### EventBridge Pipes Pattern

Use EventBridge Pipes for serverless integration:

1. Source: DynamoDB Stream
2. Enrichment: Optional (Lambda or API Gateway)
3. Target: MySQL database or Lambda that triggers projections

## Idempotency

Use the `message_id` (ULID) for idempotency checks:

```php
$existingEvent = $pdo->query(
    "SELECT * FROM event_store WHERE message_id = ?",
    [$messageId]
)->fetch();

if ($existingEvent) {
    // Already processed
    return;
}
```

## Local Testing

Use DynamoDB Local for development:

```bash
docker run -p 8000:8000 amazon/dynamodb-local
```

Configure your client:

```php
$client = new DynamoDbClient([
    'region' => 'us-east-1',
    'endpoint' => 'http://localhost:8000',
]);
```

Note: DynamoDB Local may have limitations with Streams. Consider using LocalStack for full Streams support:

```bash
docker run -p 4566:4566 localstack/localstack
```

## Performance Characteristics

### Write Throughput

- **Single Aggregate**: ~1,000 writes/sec
- **100 Aggregates**: ~100,000 writes/sec
- **1,000+ Aggregates**: ~1,000,000+ writes/sec

Throughput scales linearly with the number of unique stream names (aggregate roots).

### Read Performance

- **Query by stream_name**: Efficient partition key lookup
- **Range queries**: Fast sort key operations
- **Pagination**: Automatic via LastEvaluatedKey

## Optimistic Locking

The implementation uses conditional writes:

```php
// Only succeeds if stream_position doesn't exist
'ConditionExpression' => 'attribute_not_exists(stream_position)'
```

On conflicts, `UnableToPersistMessages` exception is thrown, triggering retry logic in command handlers.

## Migration from MySQL

To migrate existing events to DynamoDB:

1. Export events from MySQL
2. Batch write to DynamoDB using `BatchWriteItem`
3. Configure DynamoDB Streams consumer
4. Switch application to use `DynamoDbMessageStorage`

## Best Practices

1. **Stream ARN Management**: Store Stream ARN in config after table creation
2. **Error Handling**: Implement retry logic for consumer failures
3. **Monitoring**: Set up CloudWatch alarms for Lambda errors
4. **Cost Optimization**: Use batch processing in consumers
5. **Testing**: Use DynamoDB Local or LocalStack for local development

## Troubleshooting

### Table Creation Fails

- Verify AWS credentials and permissions
- Check table name doesn't exist with different schema
- Ensure DynamoDB service is available in your region

### Stream Not Enabled

- Check table was created with StreamSpecification
- Verify LatestStreamArn is present in DescribeTable
- Recreate table if streams weren't enabled initially

### Consumer Not Processing

- Verify Lambda event source mapping is active
- Check Lambda function logs in CloudWatch
- Ensure Stream ARN is correct

### Write Conflicts

- Expected behavior with optimistic locking
- Command handlers should retry automatically
- Monitor conflict rates in CloudWatch

## References

- [AWS DynamoDB Streams](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/Streams.html)
- [DynamoDB Local](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/DynamoDBLocal.html)
- [LocalStack](https://localstack.cloud/)
- [EventSauce](https://eventsauce.io/)

