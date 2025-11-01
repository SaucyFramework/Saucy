<?php

namespace Saucy\Core\Laravel\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;
use Illuminate\Console\Command;
use Saucy\MessageStorage\DynamoDb\DynamoDbTableManager;

final class EnsureDynamoDbTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saucy:dynamodb:ensure-tables 
                            {--table=event_store : Table name}
                            {--region= : AWS region}
                            {--endpoint= : DynamoDB endpoint (for local testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensure DynamoDB event store table exists with DynamoDB Streams enabled';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tableName = $this->option('table');
        $region = $this->option('region');
        $endpoint = $this->option('endpoint');

        $config = [];
        if ($region) {
            $config['region'] = $region;
        }
        if ($endpoint) {
            $config['endpoint'] = $endpoint;
        }

        $dynamoDbClient = new DynamoDbClient($config);
        $tableManager = new DynamoDbTableManager($dynamoDbClient);

        try {
            $this->info("Checking DynamoDB table '{$tableName}'...");

            if ($tableManager->tableExists($tableName)) {
                $this->info("Table '{$tableName}' already exists.");
                $streamArn = $tableManager->ensureEventStoreTable($tableName);
                $this->info("Stream ARN: {$streamArn}");
            } else {
                $this->info("Creating table '{$tableName}'...");
                $streamArn = $tableManager->ensureEventStoreTable($tableName);
                $this->info("Table '{$tableName}' created successfully!");
                $this->info("Stream ARN: {$streamArn}");
                $this->newLine();
                $this->warn('Important: Configure your consumer to listen to this Stream ARN.');
                $this->warn('Examples: AWS Lambda, Kinesis Data Streams, or custom consumer.');
            }

            return Command::SUCCESS;
        } catch (AwsException $e) {
            $this->error("AWS Error: {$e->getAwsErrorMessage()}");
            $this->error("Error Code: {$e->getAwsErrorCode()}");

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}

