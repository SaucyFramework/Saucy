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
    lane: 'money',             // optional projection lane (see "Projection Lanes")
)]
```

### Gap guard

A `global_position` is reserved when a row is INSERTed but only becomes visible when its
transaction COMMITs, so position 3 can be readable while position 2 is still in flight. A reader
that trusts `max(global_position)` consumes 3, checkpoints past 2, and **skips 2 forever**.

Every all-stream reader (the per-subscription poller and the projection lanes alike) therefore
stops at a *safe ceiling* instead: it will not consume a row until every position below it is
either visible or older than the grace window.

```php
// config/saucy.php
'all_stream_projection' => [
    'gap_grace_in_seconds' => 10,   // 0 disables the guard (legacy behaviour)
],
```

**Two assumptions this rests on:**

1. **No event-store insert stays uncommitted for longer than `gap_grace_in_seconds`.** A
   transaction that holds an allocated position longer than that can still be skipped.
2. **`created_at` is a faithful proxy for INSERT time.** It is stamped from the *application*
   clock in `persist()`, not by the database, so every writer's clock must agree to within the
   grace window, and **nothing may backdate `created_at` while the store is taking live writes**.

The second one has a concrete failure mode. Positions 1-3 are settled, position 4 is allocated by
a live transaction that has not committed, and an importer commits position 5 stamped with a
historical `created_at`. Now *no* row looks young, the whole store looks settled, the ceiling
jumps to 5 - and when 4 finally commits it has already been passed. `Carbon::setTestNow`,
historical importers and backfill seeders all produce exactly this shape.

The mitigation is operational, not algorithmic:

- run backdated imports and seeders against a store that is **not** taking live writes, or with
  `gap_grace_in_seconds = 0` on a quiet store;
- never backdate `created_at` on a live store.

### Upgrading

The guard is **opt-in for existing installations**. `mergeConfigFrom` does not merge nested keys,
so a host that already publishes its own `all_stream_projection` block would otherwise have the
guard switched on silently by a `composer update`. Both fallbacks in code are therefore `0`, and
only the package's shipped `config/saucy.php` carries the `10`: a fresh install gets the guard,
an existing one must add the key to its own block to enable it.

Enabling it costs, per poll: 2-4 extra queries per subscription on the legacy path (once per page
for a whole lane on the lane path), plus up to `gap_grace_in_seconds` of latency each time an
auto-increment value is burnt. Weigh that against a silently skipped event.

Holes are not rare, and most of them are permanent: every optimistic-concurrency conflict burns an
auto-increment value InnoDB never reuses. The guard has to tell those apart from a genuine
in-flight write, and it does so by age - a hole is only respected while the rows around it are
young. So a hole resolves itself either way:

- the transaction commits, the hole fills, and the reader moves on immediately;
- or nothing arrives, the row above the hole ages past the window, and the hole is written off.

A reader therefore stalls for at most `gap_grace_in_seconds`, and only while a hole actually
exists. When the stream is contiguous - the overwhelmingly common case - the ceiling is the head
and nothing changes.

On the legacy path with `keep_processing_without_new_messages_before_stop_in_seconds = 0`, a poll
that is pinned by a hole returns no messages and the job simply stops; the rows above the hole are
consumed on the next persisted event or cron tick, not by that job spinning.

A bulk import committed inside the grace window is bounded too: the reader looks at most
`SAFE_CEILING_SCAN_CAP` (10 000) positions above the last settled row per call, and advances in
those steps until it has caught up.

The naive rule "stop just below the oldest young row" is **wrong**, and the implementation does
not use it: if T1 allocates 5 and T2 allocates 6 and commits first, the lowest visible young
position is 6, and stopping at 5 would treat the still-in-flight 5 as consumed. The reader
instead checks that the region above the last settled row is *contiguous* before raising the
ceiling to the head.

`awaitProjection` needs no changes, but note the trade: awaiting an event that sits just above an
in-flight hole waits up to the grace window. That is the correct behaviour - the alternative is
reporting a projection as up to date while an earlier event is still missing from it.

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

## Projection Lanes

By default every persisted event fans out to one poll job **per matching all-stream projector**.
Each job takes its own lease, reads and writes its own checkpoint, writes its own activity rows
and exits as soon as one poll comes back empty. With a few hundred projectors the number of
queue invocations is `events x matching projectors`, which on a per-invocation-billed runtime
(AWS Lambda) is the dominant cost.

A **lane** is one long-lived poller that reads the all-stream **once**, in global order, and
dispatches each event in memory to every member projector that subscribes to it. Cost becomes
`events x lanes`. Each member keeps its **own checkpoint**, under its **own subscription id** and
in its own checkpoint store, so enabling lanes needs no data migration and any member can be
pulled back out of the lane at any time.

Lanes are **opt-in**: with `saucy.lanes` empty, every code path behaves exactly as before.

### Configuration

```php
// config/saucy.php
'lanes' => [
    // Lanes are enabled when this array is non-empty. Projectors that name no lane (or name a
    // lane that is not configured) fall into 'default', which is created with these defaults
    // when it is not configured explicitly.
    'default' => [
        'queue' => null,                   // queue the lane poll job runs on
        'page_size' => 100,                // events read per page
        'process_timeout' => 240,          // seconds a lease lives; the job self-chains before it
        'keep_alive_seconds' => 30,        // keep polling this long after the last empty poll
        'sleep_ms' => 250,                 // sleep between empty polls while kept alive
        'catch_up_threshold' => 1000,      // members further behind than this run standalone
        'commit_batch_size' => null,       // null = page_size; money lanes want this small
        'retry_budget_seconds' => 10,      // per-event retry budget before it is poison
        'quiesce_wait_seconds' => 20,      // how long a replay/swap waits for the lane to yield
        'gap_grace_seconds' => null,       // null = use all_stream_projection.gap_grace_in_seconds
    ],
    'money' => [ /* same keys, all optional */ ],
],

// Optional operator override: subscription id => lane name. Wins over the attribute.
'lane_assignments' => [
    // 'paynl_ticketing_payment_ledger_reactor' => 'money',
],
```

Assign a projector to a lane with the attribute:

```php
#[Projector(lane: 'money')]
class PaymentLedgerReactor extends TypeBasedConsumer { /* ... */ }
```

Resolution order is `lane_assignments` -> the attribute -> `default`.

### The catch-up window

A member whose checkpoint is more than `catch_up_threshold` behind the store head would drag the
whole lane back to its position, so the lane excludes it and starts a **standalone**
`AllStreamPollSubscriptionJob` for it instead - the legacy path, on the member's own lease. When
that job catches up and stops, it bumps the lane's membership version and the lane takes the
member back at its next page boundary. This is what makes a replay safe: `replaySubscription()`
resets the checkpoint to the start, which puts the member far behind the window.

Note that a bump cannot restart a lane that has already stopped; the next persisted event does
that, through the normal trigger path.

The cron entry point (`AllStreamSubscriptionProcessManager::startProcesses()`) starts every lane
**and** every all-stream subscription that belongs to no lane - background replay subscriptions
(`replay__*`) are never lane members and would otherwise never be started.

### Synchronous projectors (`awaitProjection`)

`RunAllSubscriptionsInSync::runSubscriptionInSync()` makes specific projectors run inline in the
request. With a lane awake, that must not double-handle events. The member is therefore
**claimed** on the lane coordinator before the inline poll: the lane re-reads the claimed set at
its next page boundary, treats claimed members as out-of-lane, and acknowledges. Only then does
the inline poll run; the claim is released afterwards. Whenever the claim cannot be made safe
(another process holds the member's lease, or the lane does not acknowledge within ~2 seconds)
the lane is started instead, so the event is never left unprocessed.

A claim carries no TTL, so it is honoured only while the claimer still holds the member's own
lease (taken before the claim, dropped before the release). If an inline run dies between the two
- a Lambda timeout or OOM - the lane sees a claim with no lease behind it, takes the member back
and drops the stale claim. Without that, one crashed request would evict a projector from its lane
permanently.

`startProcess()` (the dashboard "trigger" button, and the poison-message manager after a retry or
skip) does **not** run a run-in-sync member inline when lanes are enabled: it bumps the membership
version and wakes the lane. Both callers are async-tolerant - they need the work to happen soon,
not before they return.

Budget for it: an awaited persist costs roughly three times the statements of the legacy inline
path, because the claim and the release are each a row-locked coordinator transaction on top of
the member's own lease. That is the price of never double-handling while a lane is live; keep
`awaitProjection` for the projectors whose read model the response actually reads.

### Queue requirements

A lane job holds its lease for its whole run, so it must never be retried or redelivered while it
is still running: two live copies would both pass `isActive($laneId, $processId)` and fan the same
events out twice. The job therefore sets `tries = 1` and a worker `timeout` of
`process_timeout + 30`, and it stops polling and self-chains **30 seconds** before its lease
expires (rather than the 5 seconds the legacy per-subscription job uses), so that everything
waiting on `isActive($laneId)` - a sync claim, an operator quiesce - can safely read an inactive
lease as "no runner can still be mid-page".

**The queue connection's `retry_after` (or the SQS queue's visibility timeout) MUST exceed
`process_timeout + 30`.** With the default 240 s lane timeout that means at least 270 s. A shorter
value lets the broker redeliver a job that is still running.

The **worker's** own execution limit (`php artisan queue:work --timeout`, or the Vapor queue
`timeout`) must exceed it too. A worker killed mid-page does not double-process anything, but it
strands the lane's lease until it expires, so the lane stalls for up to `process_timeout`.

### Operational notes

- **Head-of-line blocking is the trade-off.** One slow handler, or one event retrying against its
  `retry_budget_seconds` budget, stalls every other member in the same lane. Put reactors with
  money side effects or expensive third-party calls in their own lane.
- **Per-member `store_checkpoint` activity rows are no longer written.** The lane logs one
  `started_poll` / `loading_events` / `loaded_events` / `store_checkpoint` trail under
  `lane__<name>`, carrying a `members_advanced` count and a per-member `messages_processed`
  summary. A projector's activity detail page therefore goes quiet while it is a lane member.
  Poison rows still land under the **member's** id. An **idle** poll writes no activity rows at
  all - a kept-alive lane polls continuously, and a trail per empty poll would bury the useful
  rows.
- **A lane preserves global order** for its members, and **cannot scale out**: it is a single
  poller by construction. When lag grows, split the lane rather than adding workers.
- **Lag alarm:** `laneHead - min(member position)` for the lane's members.
- **A replay or hot swap can fail with "did not acknowledge in time".** Both quiesce the member
  through the lane and wait `quiesce_wait_seconds` (default 20) for it to yield; on a busy lane
  with a large `page_size` that can time out. The failure is safe and self-cleaning - the pause
  the quiesce took is undone before the error propagates - so just retry the action. Raising
  `quiesce_wait_seconds` beyond the calling request's own timeout is counter-productive: a
  SIGKILLed request is the one case that *would* leave the member paused.
- **The gap guard is computed once per poll for the whole lane** (`gap_grace_seconds`, falling
  back to `all_stream_projection.gap_grace_in_seconds`), where the legacy path pays for it once
  per subscription per poll. See "Gap guard" above; a lane holds every member at the same safe
  ceiling, so no member's checkpoint can pass a position that is still in flight. The catch-up
  window is measured against that ceiling rather than the raw head - otherwise a hole that pins
  the lane would look like every member falling behind, and the whole lane would be ejected to
  standalone catch-up jobs that are equally forbidden to close the gap.
- **A money lane wants a small `commit_batch_size`**, because the redelivery window after a crash
  is one commit batch.
- **`lane__<name>` is a lease id, not a projector.** It appears in `running_processes` and in the
  activity log; the `lane_coordination` row's `membership_version` / `acknowledged_version` are
  coordination counters, not stream positions.
- **An event the `TypeMap` cannot resolve** (an unmapped event type or stream-name type, usually
  a projector deployed ahead of its events) becomes a poison message for every member that
  subscribes to it, and each member's own failure mode is applied. It does not take the lane down:
  a throw out of the poll loop would crash-loop every member in the lane. Such a poison row can be
  **skipped**, but a **retry** will fail again for as long as the event still cannot be decoded -
  deploy the missing mapping first, or skip the row if the event is genuinely unwanted.
- Aggregate-scoped projectors (`#[AggregateProjector]`) are unaffected: they keep one lease per
  aggregate instance.

### Coordination storage

The lane coordinator lives in the `lane_coordination` table by default
(`IlluminateLaneCoordinator`), so **hosts must run the package migrations before enabling lanes,
or bind their own `LaneCoordinator`**. A host that binds its own stores (checkpoints and leases on
DynamoDB, say) should do the latter: implement
`Saucy\Core\Subscriptions\Lanes\LaneCoordinator` against anything with an atomic counter - on
DynamoDB that is an `UpdateItem ... ADD membership_version :one` with `ReturnValues: UPDATED_NEW`,
plus a string set for the claims.

Two contract points any implementation must honour:

- `bumpMembership()`, `claim()` and `release()` return the version of **their own** increment,
  produced under a row lock, so two concurrent bumps return two distinct values. Callers wait for
  `acknowledgedVersion >= theVersionTheyGotBack`.
- `acknowledge($lane, $version)` clears `structuralPending` **only** when no bump landed after the
  acknowledged version. Clearing it unconditionally would swallow a structural change that
  arrived while the lane was evaluating, and the lane would never re-evaluate for it.

The Illuminate implementation does every mutation inside a transaction with `lockForUpdate()`. If
your application wraps command handling in an outer `DB::transaction()`, that row lock is held
until the outer transaction commits - keep lane coordination out of long-running transactions.

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
