<?php

declare(strict_types=1);

namespace Saucy\Core\Framework\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\Facades\App;

/**
 * Helper for creating Saucy DynamoDB tables from Laravel migrations.
 *
 * Usage in a migration:
 *
 *   public function up(): void
 *   {
 *       SaucyDynamoDbMigration::up();
 *   }
 *
 *   public function down(): void
 *   {
 *       SaucyDynamoDbMigration::down();
 *   }
 */
final class SaucyDynamoDbMigration
{
    public static function up(): void
    {
        static::manager()->createTables();
    }

    public static function down(): void
    {
        static::manager()->deleteTables();
    }

    private static function manager(): DynamoDbTableManager
    {
        return new DynamoDbTableManager(
            client: App::make(DynamoDbClient::class),
            prefix: config('saucy.dynamodb.prefix', ''), // @phpstan-ignore-line
        );
    }
}
