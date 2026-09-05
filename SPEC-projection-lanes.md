# SPEC: Projection lanes

## Problem

Today every persisted event fans out to one poll job **per matching all-stream projector**.
Each of those jobs takes its own lease (`RunningProcesses`), reads and writes its own
checkpoint, writes its own activity-log rows, and exits as soon as one poll comes back
empty (`keep_processing_without_new_messages_before_stop_in_seconds = 0` in production).
With ~265 all-stream projectors the number of queue invocations, lease operations and
checkpoint writes is `events × matching projectors`. On a per-invocation-billed runtime
(Laravel Vapor / Lambda) that is the dominant cost, and the median events-per-poll is 1.

## Goal

Run all-stream projectors as a small number of **lanes**. A lane is one long-lived poller
that reads the all-stream **once**, in global order, and dispatches each event in memory to
every member projector that subscribes to it. Each member keeps its **own checkpoint** (same
`CheckpointStore`, same subscription id as today — no data migration). Cost becomes
`events × lanes`, lanes being 1–5.

Non-goals for this PR:

- Aggregate-scoped projectors (`#[AggregateProjector]`, `StreamSubscription`) are untouched.
- No change to `CheckpointStore`, `RunningProcesses`, `PoisonMessageStore` interfaces.
- No dashboard work; existing per-subscription checkpoints/poison rows keep working.
- Lanes are **opt-in**. With `saucy.lanes` empty every existing code path behaves exactly as
  before, and the existing tests must keep passing unchanged.

## Configuration

```php
// config/saucy.php
'lanes' => [
    // Lanes are enabled when this array is non-empty. Projectors that name no lane (or name
    // a lane that is not configured) fall into 'default', which is created with these
    // defaults when it is not configured explicitly.
    'default' => [
        'queue' => null,                   // queue the lane poll job runs on
        'page_size' => 100,                // events read per page
        'process_timeout' => 240,          // seconds a lease lives; job self-chains before it
        'keep_alive_seconds' => 30,        // keep polling this long after the last empty poll
        'sleep_ms' => 250,                 // sleep between empty polls while kept alive
        'catch_up_threshold' => 1000,      // members further behind than this run standalone
        'commit_batch_size' => null,       // null = page_size; money lanes want this small
        'retry_budget_seconds' => 10,      // per-event retry budget before it is poison
    ],
    'money' => [ /* same keys, all optional */ ],
],

// Optional operator override: subscription id => lane name. Wins over the attribute.
'lane_assignments' => [
    // 'paynl_ticketing_payment_ledger_reactor' => 'money',
],
```

Attribute:

```php
#[Projector(lane: 'money')]          // new optional parameter, default null
```

`ProjectorConfig` gets a `?string $lane` (serialised in `toPayload`/`fromPayload`, defaulting
to `null` for cached maps built before this change).

## Components (all under `core/src/Subscriptions/Lanes/`)

### `LaneConfig` (readonly value object)

Built from the config block above with defaults filled in. `name`, `queue`, `pageSize`,
`processTimeoutInSeconds`, `keepAliveInSeconds`, `sleepInMicroseconds`, `catchUpThreshold`.

### `LaneRegistry`

Built in `SaucyServiceProvider` from `saucy.lanes`, `saucy.lane_assignments` and the
`AllStreamSubscriptionRegistry`. Answers:

- `enabled(): bool` — `saucy.lanes` non-empty.
- `lanes(): array<string, LaneConfig>`
- `laneFor(string $subscriptionId): ?LaneConfig` — every all-stream subscription maps to a
  lane when enabled (`lane_assignments` → attribute → `'default'`).
- `members(string $lane): array<string, AllStreamSubscription>` keyed by subscription id.
- `eventTypesFor(string $lane): array<string>` — union of member event types.
- `laneSubscriptionId(string $lane): string` — `'lane__' . $lane`. This is the id used for
  the lane's lease in `RunningProcesses` and for the lane's activity-log rows.

Replay subscriptions (`replay__*`) are never lane members.

### `LaneCoordinator`

Interface for the shared state a lane needs beyond leases and checkpoints: an atomically
incremented **membership version**, the lane's **acknowledged version**, and the set of
members currently **claimed** by an inline (sync) run.

```php
interface LaneCoordinator
{
    /** ONE consistent read of the row; what the lane runner uses at a page boundary. */
    public function read(string $lane): LaneCoordinationState;

    /** Atomic increment returning THIS bump's value (row lock; two concurrent bumps -> two distinct versions). */
    public function bumpMembership(string $lane, bool $structural = true): int;
    public function structuralPending(string $lane): bool;   // cleared by acknowledge()
    public function membershipVersion(string $lane): int;
    /** Clears structuralPending ONLY when membershipVersion <= $version. */
    public function acknowledge(string $lane, int $version): void;
    public function acknowledgedVersion(string $lane): int;

    /** Sync-claim set: members an inline run currently owns. Read as ONE item per lane. */
    /** claim/release also perform the (non-structural) claim bump and return its version. */
    public function claim(string $lane, string $memberId): int;
    public function release(string $lane, string $memberId): int;
    /** @return array<string> member ids */
    public function claimedMembers(string $lane): array;
}
```

Implementations in this PR:

- `IlluminateLaneCoordinator` — one row per lane in a new `lane_coordination` table
  (`lane` PK, `membership_version` unsigned bigint, `acknowledged_version` unsigned bigint,
  `claimed_members` json). `bumpMembership` is `UPDATE ... SET membership_version =
  membership_version + 1` followed by a read (upsert the row on first use); claim/release are
  read-modify-write inside a transaction with `lockForUpdate`. Add the migration under
  `migrations/`.
- `InMemoryLaneCoordinator` for tests.

Bind the interface as a singleton in the provider. Note in the PR body that a host binding
its own stores (ticket-api uses DynamoDB) implements this with `UpdateItem ADD` for the
version and a string set for claims — a dozen lines.

Two kinds of bump, and the lane reacts differently:

- **Structural bump** (pause/resume/replay of a member, poison retry/skip, catch-up job
  finished): the lane runs a **full membership evaluation** (below).
- **Claim bump** (sync claim / release): the lane only re-reads `claimedMembers(lane)` and
  drops/re-adds those members (a re-added member's position is re-read from the checkpoint
  store). No per-member `isActive` reads.

Because the lane cannot tell the two apart from the counter alone, `bumpMembership` takes an
optional `bool $structural = true` argument, stored as a flag on the row
(`structural_pending`) that the lane clears when it acknowledges. Claim bumps set only the
counter.

### `LaneMembership` (computed at membership evaluation)

For each member of the lane, read once:

- position: `CheckpointStore::get(memberId)` or `startingFromPosition` when not found.
- paused: `RunningProcesses::isPaused(memberId)`.
- halted: member `failureMode === Halt` **and** the member has an unresolved poison message.
  Read `PoisonMessageStore::getUnresolved()` **once** for the whole lane and group by
  subscription id; never one call per member.
- standalone-active: `RunningProcesses::isActive(memberId)` (a catch-up job, a sync-claim, or
  a legacy poll job holds the member's own lease).

Then `laneHead = AllStreamReader::maxEventId()` (already queried per poll). Do **not** derive
the head from member positions: a member pinned by `startFrom` above the store head (which
happens on staging/demo) would otherwise eject every other member. A member is **in-lane**
when it is not paused, not halted, not standalone-active, not claimed, and
`position >= laneHead - catchUpThreshold`. A member above `laneHead` is in-lane and simply
skips everything.

A member that is not paused/halted/active but is **behind the window** is a **catch-up**
member: the lane calls `AllStreamSubscriptionProcessManager::startStandalone(memberId)` for it
(the existing `AllStreamPollSubscriptionJob` path, which takes the member's own lease) and
excludes it. When that job finishes (its `messagesHandled === 0` stop branch) it bumps the
member's lane membership version so the lane re-evaluates and takes the member back once it is
inside the window.

Membership is evaluated at lane process start and whenever the membership version read at a
page boundary differs from the last one seen. After every evaluation the lane writes
`acknowledge(lane, version)`.

### `LaneRunner`

The core. Pure PHP; constructed from `LaneConfig`, the members, `AllStreamReader`,
`EventSerializer`, `TypeMap`, `CheckpointStore`, `RunningProcesses`, `PoisonMessageStore`,
`PoisonMessageRecorder`, `ActivityStreamLogger`, `LaneCoordinator`, and a
`callable(string $memberId): void` that starts a standalone catch-up job (a callable, not the
process manager, to avoid the `AllStreamSubscriptionProcessManager → LaneProcessManager →
LaneRunner` cycle). Method `poll(int $timeoutInSeconds): int` returns the number of
events read from the store in this poll (0 means idle).

One poll:

1. Read the coordinator row once: if the version changed since last seen, do a full
   evaluation when `structural_pending` is set, otherwise only re-sync the claimed set; then
   `acknowledge(lane, version)`. First run always does a full evaluation. This step runs before
   **every** page read and on every idle loop iteration. Order within a poll is strictly:
   commit previous page → read coordinator → (re-evaluate) → ack → read next page.
2. `fromPosition = min(position of in-lane members)`. If no in-lane members, return 0.
3. `paginate(new AllStreamQuery(fromPosition, pageSize, eventTypesUnion))`.
4. For every stored event, in order, for every in-lane member whose `position <
   event.globalPosition` and whose event types include `event.eventType`:
   - `MessageConsumerThatHandlesBatches` members get `beforeHandlingBatch()` once before the
     first event of the page and `afterHandlingBatch()` after the last.
   - Deserialise the event **once per event** (not per member); build a
     `MessageConsumeContext` per member (it carries the member's `subscriptionId`).
   - `EventHandlerWithRetry::handle(member.consumer, context, $retryPolicy)`. Introduce a
     `RetryPolicy` value object (initial delay, multiplier, max total seconds) with the current
     constants as the default so the existing callers are unchanged; lanes pass one built from
     `retry_budget_seconds` (default 10). A 60 s retry stalls every member in the lane, and the
     readme must say that this is the head-of-line trade-off of lanes. On exhausted retries record the
     poison message (`PoisonMessageRecorder::record(memberId, …)`) and apply the **member's**
     failure mode:
     - `Halt`: the member leaves the lane for the rest of this process. Its in-memory position
       stays at the last event it handled successfully (so the poison event is re-delivered
       after resolution, exactly like today). Other members continue.
     - `PauseStream`: the member skips further events from that stream name (in-memory set
       plus `PoisonMessageStore::hasUnresolvedForStream`), advancing its position past them —
       same semantics as `AllStreamSubscription`.
     - `SkipMessage`: advance past the event.
   - On success set `member.position = event.globalPosition`.
   - Members that were in-lane but did **not** subscribe to this event type also advance to
     `event.globalPosition` (the lane read covered every type any member wants, so there is
     nothing between for them to see).
5. Time budget: stop reading when `time() - start >= timeout` (like today's `queue_timeout`).
6. Commit: every `commit_batch_size` events (per-lane setting, default = `page_size`) and at
   page end, store each member's checkpoint **only if it moved** (`Checkpoint(memberId,
   position)`); the checkpoint ids are the members' own ids. The readme should say a money lane
   wants a small `commit_batch_size` because the redelivery window after a crash is one batch.
7. If the page was empty and the loop did not time out, advance every in-lane member to
   `AllStreamReader::maxEventId()` and commit the ones that moved.
8. Activity log: one `started_poll` / `loaded_events` / `store_checkpoint` trail for the lane
   under stream id `lane__<name>`, carrying a `members_advanced` count and per-member
   `messages_processed` summary; a `poison_message` row under the **member's** id when a
   poison occurs. Do **not** write per-member rows for idle members.

### `LanePollJob`

Queue job `(laneName, processId)` modelled 1:1 on `AllStreamPollSubscriptionJob`:

- `isActive(laneSubscriptionId, processId)` else stop lease and `startNewProcess()`.
- `timeLeft(processId) - 5`; if `<= 0` stop and `startNewProcess()`.
- `reportStatus('running')`, `poll($timeLeft)`.
- If 0 handled: start/keep the idle timer; when `keep_alive_seconds` elapsed
  `reportStatus('stopping')`, `stop(processId)`, return; else `usleep(sleep)`.
- Loop with `while (true)`, **not** recursion (`AllStreamPollSubscriptionJob` recurses; with a
  240 s lease and 250 ms sleeps that is ~1000 frames). Self-chain by re-dispatching with a new
  lease before the current one runs out.
- `displayName()` → `"projection lane: {$laneName}"`, `tags()` → `['projection-lane',
  'lane:' . $laneName, 'processId' => …]`.
- On any throwable: `stop(processId)` and rethrow (as today).

### `LaneProcessManager`

`startLaneIfNotRunning(string $lane)`: `isActive(laneId)` → return; `start(laneId,
processId, now + process_timeout)` catching `StartProcessException`; dispatch `LanePollJob`
on the lane's queue. `startAllLanes()` for cron use. `startLanesThatRequireEvents(array
$eventTypes)`: for each lane whose union intersects, `startLaneIfNotRunning`.

### Integration into `AllStreamSubscriptionProcessManager`

Inject `LaneRegistry` and `LaneProcessManager` (nullable / no-op when lanes disabled). Then:

- `startProcessesThatRequireEvents(eventTypes)`: when lanes enabled →
  `LaneProcessManager::startLanesThatRequireEvents`. **Exception:** if
  `RunAllSubscriptionsInSync::isRunSync(member.consumer)` is true for a member that matches,
  run the **sync claim** for that member (below) instead of relying on the lane.
- `startProcesses()` (cron): when lanes enabled → `startAllLanes()`.
- `startProcess(name)` (dashboard "trigger", poison-message post-resolution): when the
  subscription belongs to a lane → bump membership version and `startLaneIfNotRunning`.
- New public `startStandalone(name)`: today's `startStreamIfNotRunning` behaviour (member's
  own lease + `AllStreamPollSubscriptionJob`), used by the lane for catch-up members.
- `pause(name)` / `resume(name)`: existing behaviour, then bump the member's lane version.
- New `quiesceMember(memberId, reason)` helper on `LaneProcessManager`: `pause(memberId,
  reason)` → structural bump → wait until `acknowledgedVersion >= version` **or** the lane has
  no active lease (bounded `BackOffRunner` wait, like today's lock wait) → take the member's
  own lease with `ignorePaused: true` (exactly what `replaySubscription` does today). Returns
  the process id so the caller can `stop()` it. After this the lane is guaranteed not to be
  handling the member.
- `replaySubscription(name)`: when in a lane → `quiesceMember`, `prepareForReplay()` (resets
  the member's checkpoint), `resume(memberId)`, `stop(processId)`, structural bump,
  `startLaneIfNotRunning`. The member is now far behind the window, so the lane hands it to a
  standalone catch-up job; when that job stops inside the window the lane takes it back.
- `BackgroundReplayManager::swapReplay` and `cancelReplay` today wait on
  `isActive(memberId)` before swapping tables. In lane mode the lane holds `lane__<name>`, not
  the member id, so that wait returns immediately and the swap would race a page in flight.
  Both must call `quiesceMember` for the main subscription when the member is in a lane, and
  `resume` + structural bump + `startLaneIfNotRunning` in their `finally`. Add a test for the
  swap path.

### Sync claim (the `awaitProjection` path)

`AwaitProjected` in the host application sets `RunAllSubscriptionsInSync` for specific
projector classes so the persist hook runs them inline in the request, which is what keeps
mutations fast. With a lane that is awake this must not double-handle events. Add
`LaneProcessManager::runMemberInline(AllStreamSubscription $member)`:

1. `RunningProcesses::start(memberId, processId, now + member timeout)` — the member's own
   lease. If that throws `StartProcessException` (someone else holds it) → **give up** (see
   below).
2. `claim(lane, memberId)`; `version = bumpMembership(lane, structural: false)`.
3. If `isActive(laneId)`: wait until `acknowledgedVersion(lane) >= version` **or** the lane is
   no longer active, polling with a short linear back-off capped at ~2 seconds. If the cap is
   hit → `release`, `stop(processId)`, bump, **give up**.
4. `member->poll()` (the existing `AllStreamSubscription::poll`, so this is exactly today's
   inline run).
5. `finally`: `stop(processId)`, `release(lane, memberId)`, `bumpMembership(lane, false)`.

**Give up** always means: call `startLaneIfNotRunning(lane)` before returning, so the event is
processed by the lane even if the lane had stopped in that window; the caller then falls back
to waiting on the checkpoint as it does today. The sync path replaced the async trigger for
this persist, so a silent return would leave the event unprocessed until an unrelated persist.

Lane side: the lane re-reads the claimed set whenever the version changes at a page boundary
and treats claimed members as out-of-lane, so the inline run and the lane never dispatch the
same event to the same member. Two concurrent claims for different members are independent
because the claimed set is a set and the bump is atomic.

### `AllStreamPollSubscriptionJob`

Unchanged apart from one hook: in the branch where it stops because it handled 0 messages,
if `LaneRegistry::laneFor(subscriptionId)` is non-null, bump that lane's membership version
(so a catch-up member is re-evaluated). Inject `LaneRegistry` and `LaneCoordinator` via the
`handle()` method's container resolution; both must be resolvable as no-ops when lanes are
disabled so the legacy path is untouched.

### `PoisonMessageManager`

After `retry`/`skip` resolves a poison message for an all-stream subscription it already
calls `allStreamProcessManager->startProcess(id)`; with the change above that bumps the lane
version and wakes the lane. Nothing else needed, but add a test.

### `TriggerSubscriptionsJob` / `TriggerSubscriptionProcessesAfterPersist`

No API change; they call `startProcessesThatRequireEvents`, which now routes to lanes.

## Tests (workbench, SQLite in-memory, `vendor/bin/phpunit workbench/tests`)

Use `Illuminate\Support\Facades\Bus::fake()` where dispatching matters, and in-memory /
Illuminate stores otherwise. Build a small fixture of three all-stream test consumers inside
the test file or `workbench/tests/Lanes/Fixtures/` (they only need to implement
`MessageConsumer` and record what they saw). Prefer constructing `LaneRunner` directly with
hand-built `AllStreamSubscription` instances (see `AllStreamSubscriptionGapGuardTest` for how
to insert raw rows into `event_store` and build a subscription without the container).

Required cases:

1. **Fan-out once, in order.** Three members with overlapping event types; five events; each
   member sees exactly the events it subscribes to, in global order; each member's checkpoint
   equals the last event's position; the store's `paginate` is called once per page (spy).
2. **Member ahead skips.** A member whose checkpoint is already past events 1–3 handles only
   4–5.
3. **Non-subscribing member still advances.** A member subscribed only to type B, page
   contains only type A: its checkpoint moves to the page end without `handle` being called.
4. **Idle poll.** Empty page → all in-lane members advance to `maxEventId`, returns 0, and
   checkpoints that did not move are not rewritten (spy on the store).
5. **Halt isolates the member.** Member B throws on event 3 (use a consumer whose handler
   throws; make `EventHandlerWithRetry` fast by keeping the test's exception path short — if
   the 60 s retry budget makes this slow, introduce a `RetryPolicy` value object with a test
   override; do not leave a slow test). Result: poison row for B at 3; B's checkpoint = 2; A
   and C reach 5; on the next membership evaluation B is excluded (unresolved poison) and after
   `PoisonMessageStore::resolve` + version bump B is back and re-handles 3.
6. **PauseStream / SkipMessage** members behave as in `AllStreamSubscription` (one test each).
7. **Paused member excluded**; resumed member (version bump) re-included.
8. **Catch-up window.** Member far behind threshold is excluded, `Bus` sees an
   `AllStreamPollSubscriptionJob` for it, lane `fromPosition` is the min of the *in-lane*
   members; after its checkpoint is inside the window and the version bumps, it is in-lane.
9. **Sync claim.** With the lane lease held and a runner in the loop, `runMemberInline` bumps
   the version, the runner acknowledges at the next boundary and excludes the member, the
   inline poll handles the event exactly once, and after release the lane re-includes the
   member without re-handling (checkpoint already advanced).
10. **LanePollJob self-chains.** With `Bus::fake()`, a job whose lease has < 5 s left stops and
    dispatches a new `LanePollJob` with a fresh process id; a job with 0 handled and
    `keep_alive_seconds = 0` stops without re-dispatch.
11. **Trigger routing.** With lanes configured, `startProcessesThatRequireEvents([...])`
    dispatches one `LanePollJob` per lane that has a matching member and zero
    `AllStreamPollSubscriptionJob`s; with `saucy.lanes` empty it dispatches exactly what it
    does on `main`.
12. **Legacy untouched.** Existing workbench tests pass with lanes disabled.
13. **Pinned-ahead member.** A member whose checkpoint is above `maxEventId` stays in-lane,
    handles nothing, and does not eject the others (`fromPosition` is the min of the others).
14. **Swap quiesces the lane.** With a runner mid-lane and a member in background replay,
    `swapReplay` waits for the lane's acknowledgement before swapping tables (assert via the
    coordinator: ack ≥ the version the quiesce bumped) and the member is resumed afterwards.
15. **Concurrent claims.** Two sync claims for two different members on the same lane both
    proceed and both are excluded by the lane (claimed set contains both).

## Documentation

- `readme.md`: a "Projection lanes" section: what a lane is, the config block, the attribute,
  the sync-claim behaviour, the catch-up window, and the operational notes below.
- Operational notes to include: per-member `store_checkpoint` activity rows are no longer
  written under lanes, so a projector's activity detail page goes quiet (poison rows still land
  under the member id); lanes preserve global order inside a lane; a lane cannot scale
  out, split lanes when lag grows; lag alarm = `laneHead - min(member position)`; put reactors
  with money side effects in their own lane; the `lane__<name>__membership` / `__ack`
  checkpoints are coordination counters, not positions.

## Code style

PHP 8.2+, `declare(strict_types=1)` in new files, `final readonly` where the rest of the
package does it, `@PER-CS` via `vendor/bin/php-cs-fixer fix` (scope it to the files you touch),
PHPStan level 9 (`vendor/bin/phpstan analyse`) must stay clean for new code.

## Follow-ups (out of scope, list in the PR body)

- `TriggerSubscriptionsJob` is still one queue invocation per persist. With a handful of lanes
  an inline `isActive(laneId)` check in the request is cheap; wire that as a later change.
- Aggregate-scoped projectors keep one lease per aggregate instance; folding them into lanes
  changes `AwaitProjected`'s per-instance checkpoint reads and is a separate PR.
- A DynamoDB `LaneCoordinator` for hosts that bind DynamoDB stores.


## Revisions after implementation review

The design above is the original plan. A hostile review of the implementation proved several
behaviours wrong; the semantics below supersede the corresponding paragraphs.

1. **Paused-stream set is per poll, not per process.** `LaneRunner` rebuilds the PauseStream skip
   set from `PoisonMessageStore::hasUnresolvedForStream()` on every poll, exactly like
   `AllStreamSubscription::poll()`'s local `$pausedStreams`. Caching it across polls kept skipping
   a stream after an operator resolved its poison, while the checkpoint advanced past those events.

2. **`quiesceMember` is failure-safe and bounded by a lane config key.** It waits
   `quiesce_wait_seconds` (default 20), polling every 250 ms - longer than the original ~2.5 s
   back-off, but deliberately shorter than the HTTP request a replay or hot swap is triggered
   from: a wait that outlives the request gets SIGKILLed, which is the one case that would leave
   the member paused. If the wait or the lease acquisition fails it resumes the pause it took and
   bumps before rethrowing, so a failed operator action can never leave a projector paused
   forever; the operator simply retries.
   `replaySubscription()` and `BackgroundReplayManager::cancelReplay()` call it INSIDE their
   `try`/`finally`, like `swapReplay()` already did.

3. **Claims are leases, not flags.** A claim has no TTL, so it is honoured only while the claimer
   still holds the member's own lease. `LaneMembership::evaluate` checks the catch-up window
   first, then: claimed-and-lease-held or lease-held-only -> excluded but recorded in
   `eligibleExceptClaimed`; claimed with NO lease -> a stale claim from a crashed inline run, so
   the member is taken back into the lane and the claim is released.

4. **`eligibleExceptClaimed` covers lease exclusions too.** An inline run takes the member's lease
   BEFORE claiming, so a structural bump landing mid-run would otherwise freeze the member until
   the next self-chain. On a claim-type bump such a member is re-admitted only after
   `isPaused($memberId) || isActive($memberId)` says it is genuinely free. Leaving the claimed set
   is NOT sufficient: the set is a plain set and `release()` is an unset, so an inline run that
   finishes late deletes a newer run's claim for the same member, and re-admitting on that alone
   put the lane and the live inline run on the same event.

5. **`LanePollJob` is single-delivery and keeps 30 s of headroom.** `tries = 1`, and a worker
   `timeout` of `process_timeout + 30` is set at dispatch. The loop's budget is
   `timeLeft - 30 s` (not 5 s), because every `isActive(laneId)` short-circuit in `runMemberInline`
   and `quiesceMember` reads an inactive lease as "no runner can be mid-page". The queue's
   `retry_after` / SQS visibility timeout MUST exceed `process_timeout + 30`.

6. **An undeserialisable event poisons its subscribers, not the lane.** An unmapped event type or
   stream-name type is recorded as a poison message (retry count 0) for every in-lane member that
   subscribes to it, each member's failure mode is applied, and the lane keeps going. Throwing out
   of `poll()` would crash-loop the whole lane.

7. **`LaneCoordinator` gained `read()`** returning a `LaneCoordinationState` VO, so a page boundary
   costs one round-trip rather than three; `claim()`/`release()` fold in their own claim bump and
   return its version. `IlluminateLaneCoordinator` performs every mutation inside a transaction
   with `lockForUpdate()` so a bump returns its own increment, and creates its row with
   `insertOrIgnore` (a swallowed duplicate-key insert would abort an enclosing Postgres
   transaction).

8. **Idle polls are silent.** A poll that reads no events and does not time out writes no activity
   rows at all. Poison rows are always written; a `store_checkpoint` row is written only when a
   member actually handled something, because an idle lane still advances every member past event
   types nobody subscribes to - four rows a second per lane, forever, at the default sleep. The
   checkpoint WRITES themselves are unchanged; only the activity row is suppressed.

9. **`poll()` returns a `PollResult { eventsRead, timedOut }`.** "Read nothing" and "ran out of
   budget before the first event" are different states, and only the former may start the poll
   job's keep-alive countdown.

10. **`startProcesses()` (cron) also starts non-lane subscriptions.** `replay__*` subscriptions are
    never lane members, so after `startAllLanes()` the manager still starts every stream whose
    `laneFor()` is null.

11. **`LaneRunner` uses each member's own `checkpointStore`**, not a lane-level one: renamed
    projectors are wrapped in `MigratingKeyCheckpointStore`, and a lane-level store would miss the
    old key and replay history.

12. **Batch consumers** use the lane's `page_size` (a lane cannot honour per-member batch sizes)
    and are excluded from mid-page `commit_batch_size` commits, so their checkpoint is never
    written before `afterHandlingBatch()`.

13. **`maxEventId()` is read before the page**, matching `AllStreamSubscription::poll()`. Reading
    it after an empty page let an event committed during the read be skipped forever by the idle
    advance.
