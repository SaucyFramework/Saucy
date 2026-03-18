# Saucy

A Laravel package for Event Sourcing and CQRS built on [EventSauce](https://eventsauce.io/). Uses attribute-based auto-discovery for handlers, projectors, and aggregates.

## Inspiration / Dependencies

Saucy is heavily inspired by and partly uses components of [EventSauce](https://eventsauce.io/).
The event infrastructure is inspired by [Eventious](https://github.com/Eventious). [Ecotone](https://ecotone.tech/) was another source of inspiration for this project.

## Usage

Saucy consists of:
- **CommandBus**: Auto-wiring command handler registration
- **QueryBus**: Auto-wiring query handler registration
- **Projections**: All-stream and aggregate-scoped projectors with replay, management, and monitoring
- **Subscriptions**: Poll-based event processing with checkpoint tracking, poison message handling, and process management

### Command Bus

Commands can be handled by an event-sourced aggregate root or a standalone command handler.

```php
final readonly class CreditBankAccount
{
    public function __construct(
        public BankAccountId $bankAccountId,
        public int $amount,
    ) {}
}
```

Annotate the handler with `#[CommandHandler]`:

```php
// Standalone handler
class SomeCommandHandler
{
    #[CommandHandler]
    public function handleCommand(CreditBankAccount $creditBankAccount): void {
        // handle the command
    }
}

// Or within an aggregate root
#[Aggregate(aggregateIdClass: BankAccountId::class, 'bank_account')]
final class BankAccountAggregate implements AggregateRoot
{
    use AggregateRootBehaviour;

    #[CommandHandler]
    public function credit(CreditBankAccount $creditBankAccount): void
    {
        $this->recordThat(new AccountCredited($creditBankAccount->amount));
    }
}
```

Dispatch commands:

```php
$commandBus = app(CommandBus::class);
$commandBus->handle(new CreditBankAccount($bankAccountId, 100));
```

### Query Bus

Queries return results. Define a query implementing `Query<TResult>`:

```php
/** @implements Query<int> */
final readonly class GetBankAccountBalance implements Query
{
    public function __construct(public BankAccountId $bankAccountId) {}
}
```

Handle with `#[QueryHandler]`:

```php
class SomeQueryHandler
{
    #[QueryHandler]
    public function getBalance(GetBankAccountBalance $query): int {
        return $this->repository->getBalanceFor($query->bankAccountId);
    }
}
```

Query handlers can be co-located inside projectors for a clean single-source-of-truth pattern.

## Projections

Two types of projectors:

- **All-stream projectors** (`#[Projector]`): Subscribe to all events across aggregates. Useful for cross-aggregate read models.
- **Aggregate projectors** (`#[AggregateProjector]`): Scoped to a single aggregate type's stream. Each aggregate instance is processed independently, enabling parallel replay.

### All-Stream Projectors

```php
#[Projector]
class CrossAggregateProjection extends TypeBasedConsumer
{
    public function handleAccountCredited(AccountCredited $event): void
    {
        // processes every AccountCredited event across all aggregates
    }
}
```

Configuration options:

```php
#[Projector(
    pageSize: 100,             // events per poll batch
    commitBatchSize: 50,       // events before checkpoint commit
    failureMode: FailureMode::Halt,
    startFrom: 0,              // global position to start from
)]
```

### Aggregate Projectors

```php
#[AggregateProjector(BankAccountAggregate::class)]
class BalanceProjector extends IlluminateDatabaseProjector
{
    public function handleAccountCredited(AccountCredited $event): void
    {
        $bankAccount = $this->find();
        if ($bankAccount === null) {
            $this->create(['balance' => $event->amount]);
            return;
        }
        $this->increment('balance', $event->amount);
    }

    protected function schema(Blueprint $blueprint): void
    {
        $blueprint->ulid($this->idColumnName())->primary();
        $blueprint->integer('balance');
    }
}
```

Configuration options:

```php
#[AggregateProjector(
    aggregateClass: BankAccountAggregate::class,
    async: true,                    // true = queued, false = synchronous
    failureMode: FailureMode::Halt,
    migratingFrom: null,            // subscription ID for migration (see below)
)]
```

### Projector Base Classes

**`IlluminateDatabaseProjector`** — auto-creates tables, scopes queries to aggregate ID:

```php
protected function upsert(array $data): void;
protected function update(array $data): void;
protected function increment(string $column, int $amount = 1): void;
protected function create(array $data): void;
protected function find(): ?array;
protected function delete(): void;
```

**`EloquentProjector`** — projects to Eloquent models. Add `use HasReadOnlyFields` to your model:

```php
#[AggregateProjector(BankAccountAggregate::class)]
class BankAccountProjector extends EloquentProjector
{
    protected static string $model = BankAccountModel::class;

    public function handleAccountCredited(AccountCredited $event): void
    {
        // same create/update/find/increment API
    }
}
```

## Aggregate Projector Management

Aggregate projectors support replay, trigger, and bulk operations through the `StreamSubscriptionReplayManager`.

### Aggregate Instance Registry

Saucy automatically tracks all aggregate instances via a hook on the event store. Each time events are persisted, the aggregate type, ID, and latest stream position are recorded in the `aggregate_instances` table.

For existing data, run the backfill command:

```bash
php artisan saucy:backfill-aggregate-instances
```

### Replay

**Single aggregate** — resets the projector's data for that aggregate and re-processes all events inline:

```php
$manager = app(StreamSubscriptionReplayManager::class);
$manager->replayStream($subscriptionId, $streamName);
```

**All aggregates** — dispatches a Laravel `Bus::batch()` where each job resets and replays one aggregate:

```php
$batch = $manager->replayAll($subscriptionId);
// Returns an Illuminate\Bus\Batch for monitoring progress
```

Projectors must implement `MessageConsumerThatResetsStreamBeforeReplay` to support replay (both `IlluminateDatabaseProjector` and `EloquentProjector` implement this automatically).

### Trigger All

Dispatches a batch to poll every known aggregate instance, ensuring the projector is caught up:

```php
$batch = $manager->triggerAll($subscriptionId);
```

### Migrating from All-Stream to Aggregate Projector

When converting a `#[Projector]` to an `#[AggregateProjector]`, use the `migratingFrom` parameter to prevent double-processing:

```php
#[AggregateProjector(
    aggregateClass: OrderAggregate::class,
    migratingFrom: 'old_projector_subscription_id',
)]
class OrderProjector extends IlluminateDatabaseProjector { ... }
```

How it works:
1. When the aggregate projector encounters a stream with no checkpoint, it looks up the old all-stream subscription's `global_position`
2. It derives the exact `stream_position` in that aggregate's stream that corresponds to the old checkpoint
3. Stores it as the starting checkpoint — only new events are processed
4. Each aggregate self-migrates on first touch — zero downtime, gradual migration

Once all aggregates have been processed, remove the `migratingFrom` parameter.

## Poison Messages

When a projector fails to handle an event, Saucy records it as a poison message and applies the configured failure mode.

### Failure Modes

```php
#[Projector(failureMode: FailureMode::Halt)]          // stops the subscription (default)
#[Projector(failureMode: FailureMode::PauseStream)]    // pauses failing stream, continues others
#[Projector(failureMode: FailureMode::SkipMessage)]    // skips the event, continues
```

| Mode | AllStreamSubscription | StreamSubscription |
|------|----------------------|-------------------|
| `Halt` | Stops entire subscription | Stops subscription |
| `PauseStream` | Pauses failing stream, continues others | Falls back to Halt |
| `SkipMessage` | Skips single event, continues all | Skips single event, continues |

### Managing Poison Messages

```bash
php artisan saucy:poison-messages list
php artisan saucy:poison-messages list --subscription=balance_projector
php artisan saucy:poison-messages retry 1
php artisan saucy:poison-messages skip 1
```

Programmatic access:

```php
$manager = app(PoisonMessageManager::class);
$manager->listUnresolved();
$manager->listUnresolved('balance_projector');
$manager->retry(1);
$manager->skip(1);
```

### Notifications

Configure a notifiable class in `config/saucy.php` to receive notifications when poison messages are detected:

```php
'poison_messages' => [
    'notification' => [
        'notifiable' => \App\Notifications\OpsTeamNotifiable::class,
    ],
],
```

## DynamoDB Storage

Saucy supports DynamoDB as an alternative storage backend for checkpoint tracking and process management (locks). This is useful for serverless deployments or when you want to reduce load on your primary database.

### Tables

Two DynamoDB tables are used:

| Table | Key | Purpose |
|-------|-----|---------|
| `{prefix}saucy_checkpoints` | `stream_identifier` (HASH) | Checkpoint positions for all subscriptions |
| `{prefix}saucy_processes` | `pk` (HASH) | Running process locks and pause state (`PROCESS#` and `PAUSE#` prefixed keys) |

### Configuration

Add DynamoDB settings to `config/saucy.php`:

```php
'dynamodb' => [
    'prefix' => env('SAUCY_DYNAMODB_PREFIX', ''),  // e.g. 'staging_' for multi-env
],
```

### Creating Tables

Use the migration helper in a Laravel migration:

```php
use Saucy\Core\Framework\DynamoDb\SaucyDynamoDbMigration;

return new class extends Migration
{
    public function up(): void
    {
        SaucyDynamoDbMigration::up();
    }

    public function down(): void
    {
        SaucyDynamoDbMigration::down();
    }
};
```

Tables are created with `PAY_PER_REQUEST` billing and the operation is idempotent (safe to run multiple times).

### Wiring

Register the DynamoDB implementations in your service provider:

```php
use Aws\DynamoDb\DynamoDbClient;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\DynamoDbCheckpointStore;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\DynamoDbRunningProcesses;

// DynamoDB only
$this->app->bind(CheckpointStore::class, fn () => new DynamoDbCheckpointStore(
    app(DynamoDbClient::class),
    config('saucy.dynamodb.prefix') . 'saucy_checkpoints',
));

$this->app->bind(RunningProcesses::class, fn () => new DynamoDbRunningProcesses(
    app(DynamoDbClient::class),
    config('saucy.dynamodb.prefix') . 'saucy_processes',
));
```

### Gradual Migration from SQL to DynamoDB

Saucy provides migration-aware store implementations that read from DynamoDB first, fall back to SQL on miss, and write to DynamoDB. This allows a zero-downtime gradual migration:

```php
use Saucy\Core\Subscriptions\Checkpoints\MigratingCheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\DynamoDbCheckpointStore;
use Saucy\Core\Subscriptions\Checkpoints\IlluminateCheckpointStore;

$this->app->bind(CheckpointStore::class, fn () => new MigratingCheckpointStore(
    dynamoDb: new DynamoDbCheckpointStore(
        app(DynamoDbClient::class),
        config('saucy.dynamodb.prefix') . 'saucy_checkpoints',
    ),
    sql: new IlluminateCheckpointStore(
        app(DatabaseManager::class)->connection(),
    ),
));
```

Same pattern for `RunningProcesses`:

```php
use Saucy\Core\Subscriptions\Infra\MigratingRunningProcesses;
use Saucy\Core\Subscriptions\Infra\DynamoDbRunningProcesses;
use Saucy\Core\Subscriptions\Infra\IlluminateRunningProcesses;

$this->app->bind(RunningProcesses::class, fn () => new MigratingRunningProcesses(
    dynamoDb: new DynamoDbRunningProcesses(
        app(DynamoDbClient::class),
        config('saucy.dynamodb.prefix') . 'saucy_processes',
    ),
    sql: new IlluminateRunningProcesses(
        app(DatabaseManager::class)->connection(),
    ),
));
```

**How it works:**
- **Reads**: Check DynamoDB first. On miss, read from SQL and copy to DynamoDB (lazy migration).
- **Writes**: Always go to DynamoDB.
- **`getAll()`/`all()`**: Merges both sources, DynamoDB takes precedence on conflicts.

Once all checkpoints and processes have been touched (naturally through normal operation, or by triggering all projectors), switch to pure DynamoDB bindings and remove the SQL tables.

## Dashboard

The [Saucy Dashboard](https://github.com/SaucyFramework/dashboard) package provides a web UI for monitoring and managing projections. It supports both all-stream and aggregate projectors in a unified view.

Features:
- Unified projections list with type filtering (all-stream / aggregate)
- All-stream projector management: pause, resume, trigger, replay, background replay with hot-swap
- Aggregate projector management: replay/trigger per instance or in bulk via batched jobs
- Per-instance progress tracking with lag visibility
- Paginated, sortable, searchable aggregate instance list
- Poison message management with retry/skip
- Event store browser
- Processing speed charts and position history
