# Spec: Poison Message Handling & Failed Event Tracking

## Problem

When a projector handler throws an exception, the entire subscription halts. There is no retry logic, no tracking of failures, and no way to skip or isolate problematic events. A single bad event blocks all downstream processing indefinitely.

## Overview

Introduce poison message detection with exponential backoff retries, configurable failure modes per projector, a dedicated poison message store, and tooling (service + artisan command) to manage poisoned events.

---

## Failure Modes

A new `FailureMode` enum configurable per projector via attribute parameter:

```php
enum FailureMode: string
{
    case Halt = 'halt';           // Stop the entire subscription (current behavior, DEFAULT)
    case PauseStream = 'pause';   // Pause the failing stream_name, continue processing other streams
    case SkipMessage = 'skip';    // Skip the poisoned message, continue processing everything
}
```

### Configuration

Set via the existing `#[Projector]` and `#[AggregateProjector]` attributes:

```php
#[Projector(failureMode: FailureMode::PauseStream)]
class BalanceProjector extends IlluminateDatabaseProjector { ... }

#[AggregateProjector(BankAccountAggregate::class, failureMode: FailureMode::SkipMessage)]
class AccountProjector extends EloquentProjector { ... }
```

Default: `FailureMode::Halt` (backwards compatible — existing projectors behave exactly as before).

### Applicability

| Mode | AllStreamSubscription | StreamSubscription |
|------|----------------------|-------------------|
| Halt | Stops entire subscription | Stops subscription |
| PauseStream | Pauses failing stream, continues others | Equivalent to Halt (single stream) |
| SkipMessage | Skips single event, continues all | Skips single event, continues |

---

## Retry & Backoff

When a consumer throws an exception during `handle()`:

1. **Inline exponential backoff** within the same poll cycle:
   - Initial delay: **100ms**
   - Multiplier: **2x** (100ms → 200ms → 400ms → 800ms → 1600ms → 3200ms → 6400ms → 12800ms → 25600ms)
   - Maximum total retry time: **~60 seconds** (~9 retries)
   - During backoff, the subscription is held (no other events processed, preserving ordering)

2. **After exhausting retries** (~60s), the event is marked as **poisoned**:
   - A record is written to the `poison_messages` table
   - The exception is reported normally (re-thrown or logged so error tracking tools like Sentry capture it)
   - A `PoisonMessageDetected` Laravel notification is dispatched (opt-in, see Notifications section)
   - The configured `FailureMode` determines what happens next

### Backoff during poll

The backoff happens synchronously within `poll()`. The subscription does **not** advance the checkpoint or process any other events during the retry window. This preserves event ordering guarantees.

```
Event fails → sleep(100ms) → retry → sleep(200ms) → retry → ... → sleep(25600ms) → retry → POISON
```

---

## Poison Message Storage

### New Migration (auto-loaded by service provider)

Table: `poison_messages`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint (auto-increment, PK) | |
| subscription_id | string | Identifies the subscription/projector |
| global_position | bigint | The event's global position in the event store |
| message_id | string (ULID) | The event's message ID |
| stream_name | string | The stream the event belongs to |
| error_message | text | Exception message |
| stack_trace | text | Full stack trace |
| retry_count | int | Number of retries attempted before poisoning |
| status | string | `poisoned`, `resolved`, `skipped` |
| poisoned_at | timestamp | When first marked as poisoned |
| resolved_at | timestamp (nullable) | When resolved/skipped |
| created_at | timestamp | |
| updated_at | timestamp | |

**Index:** `(subscription_id, status)` — for quick lookups of unresolved poison messages per subscription.
**Index:** `(subscription_id, stream_name, status)` — for pause-stream mode lookups.

### Interface

```php
interface PoisonMessageStore
{
    public function store(PoisonMessage $message): void;
    public function resolve(int $id): void;
    public function skip(int $id): void;
    public function getUnresolved(string $subscriptionId): array;
    public function getUnresolvedForStream(string $subscriptionId, string $streamName): array;
    public function get(int $id): PoisonMessage;
}
```

Implementation: `IlluminatePoisonMessageStore` using Laravel's database connection.

---

## Subscription Behavior Changes

### AllStreamSubscription

In the `poll()` method, wrap `$this->messageConsumer->handle()` in a try-catch:

```
foreach events:
    if failureMode == PauseStream:
        check if event's stream_name has unresolved poison message → skip event

    try:
        handle event (with backoff on failure)
    catch:
        record poison message

        match failureMode:
            Halt → re-throw (current behavior)
            PauseStream → skip this event, continue loop
                          (stream is now "paused" by virtue of having an unresolved poison record)
            SkipMessage → skip this event, continue loop

    advance checkpoint as normal
```

**Pause stream gap handling:** When a stream is paused:
- The global checkpoint advances past events from other streams
- Events from the paused stream are skipped during processing
- A stream is considered "paused" if it has any unresolved poison messages in the `poison_messages` table (derived, no separate tracking)
- On unpause (poison message resolved/skipped), the next poll reads the paused stream's events from the event store using `StreamReader` from the last known position — the normal poll cycle naturally picks them up since the global checkpoint only advanced past other streams' events; the paused stream's events at those global positions will be re-encountered

**Important:** When in PauseStream mode and processing events, keep track of which global positions were skipped (because their stream was paused). The checkpoint should only advance to positions where all preceding events have been processed or explicitly skipped. Events from paused streams between the current checkpoint and the highest processed position are effectively "holes" — the checkpoint can still advance past them because those events will be re-processed via the stream reader on unpause.

### StreamSubscription

Simpler — only supports `Halt` and `SkipMessage`:

```
foreach events:
    try:
        handle event (with backoff on failure)
    catch:
        record poison message

        match failureMode:
            Halt → re-throw
            SkipMessage → skip, continue
            PauseStream → treat as Halt (single stream)

    advance checkpoint as normal
```

---

## Error Reporting

When an event is marked as poisoned:

1. **Exception reporting:** The caught exception is reported via Laravel's exception handler (`report($exception)`). This ensures error tracking tools (Sentry, Bugsnag, Flare, etc.) capture it without halting the subscription (for non-Halt modes).

2. **Notification (opt-in):** If configured, a `PoisonMessageDetected` Laravel Notification is dispatched. Users configure a notifiable and channels in `saucy.php`:

```php
// config/saucy.php
'poison_messages' => [
    'notification' => [
        'notifiable' => \App\Notifications\OpsTeamNotifiable::class,  // null to disable
    ],
],
```

The `PoisonMessageDetected` notification class:
- Implements `toMail()`, `toSlack()`, etc. (users choose channels via their notifiable's `routeNotificationFor*` methods)
- Contains: subscription ID, stream name, event type, error message, timestamp

---

## Poison Message Manager Service

A `PoisonMessageManager` service registered in the container:

```php
class PoisonMessageManager
{
    /** List unresolved poison messages, optionally filtered by subscription */
    public function listUnresolved(?string $subscriptionId = null): Collection;

    /** Retry a specific poison message. Re-processes the single event through the projector.
     *  If it fails again, immediately re-poisons (no backoff cycle). */
    public function retry(int $poisonMessageId): void;

    /** Skip/discard a poison message. Marks as 'skipped', unblocks the stream. */
    public function skip(int $poisonMessageId): void;
}
```

### Retry behavior

When `retry()` is called:
1. Load the poison message record
2. Resolve the projector/consumer from the subscription ID
3. Load the event from the event store by global position
4. Call the consumer's `handle()` with the event
5. **If successful:** Mark poison message as `resolved`, set `resolved_at`
6. **If fails:** Immediately re-poison (update error message, stack trace, increment retry count, keep status `poisoned`). No backoff cycle — the operator retries when they believe the issue is fixed.

---

## Artisan Command

`php artisan saucy:poison-messages`

### Subcommands

**List:**
```bash
php artisan saucy:poison-messages list
php artisan saucy:poison-messages list --subscription=balance-projector
```
Output: table with ID, subscription, stream, error (truncated), status, poisoned_at.

**Retry:**
```bash
php artisan saucy:poison-messages retry {id}
```
Calls `PoisonMessageManager::retry()`. Outputs success or failure with error details.

**Skip:**
```bash
php artisan saucy:poison-messages skip {id}
```
Calls `PoisonMessageManager::skip()`. Outputs confirmation.

---

## Implementation Scope

### New Files
- `core/src/Subscriptions/PoisonMessages/FailureMode.php` — enum
- `core/src/Subscriptions/PoisonMessages/PoisonMessage.php` — value object
- `core/src/Subscriptions/PoisonMessages/PoisonMessageStore.php` — interface
- `core/src/Subscriptions/PoisonMessages/IlluminatePoisonMessageStore.php` — implementation
- `core/src/Subscriptions/PoisonMessages/PoisonMessageManager.php` — service
- `core/src/Subscriptions/PoisonMessages/EventHandlerWithRetry.php` — backoff retry wrapper
- `core/src/Notifications/PoisonMessageDetected.php` — Laravel notification
- `core/src/Laravel/Commands/PoisonMessagesCommand.php` — artisan command
- `migrations/xxxx_create_poison_messages_table.php` — migration

### Modified Files
- `core/src/Subscriptions/AllStream/AllStreamSubscription.php` — wrap handle in try-catch, add failure mode logic, pause-stream filtering
- `core/src/Subscriptions/StreamSubscription/StreamSubscription.php` — wrap handle in try-catch, add failure mode logic
- `core/src/Projections/Projector.php` (attribute) — add `failureMode` parameter
- `core/src/Projections/AggregateProjector.php` (attribute) — add `failureMode` parameter
- `core/src/Framework/SaucyServiceProvider.php` — register PoisonMessageStore, PoisonMessageManager, artisan command
- `core/src/Framework/saucy.php` — add `poison_messages` config section

### Not Changed
- TypeBasedConsumer, IlluminateDatabaseProjector, EloquentProjector — error handling happens at the subscription level, not in consumers/projectors themselves.
