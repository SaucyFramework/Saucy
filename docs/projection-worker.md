# Projection worker (`saucy:work`)

By default every persist queues a `TriggerSubscriptionsJob`, which queues an
`AllStreamPollSubscriptionJob` per subscription that consumes the new events. On a serverless queue
(Lambda/SQS) that adds up to a few hops, invocations and lock writes per event per subscription.

`php artisan saucy:work` runs subscriptions in one long-lived process instead. You run a few of them
(e.g. as an ECS service). They split the subscriptions between themselves and poll only those that
have new events.

It is off by default (`saucy.worker.enabled`). With it off, nothing changes.

## How it works

Each loop iteration:

1. **Coordination.**
   - Each worker heartbeats into a member list and reads the deploy gate.
   - Every subscription belongs to exactly one live worker by rendezvous hashing, so workers agree
     without talking. Adding or removing a worker only moves its own share.
2. **Head watcher.** One primary-key range read of `event_store` per tick (150 ms) says which event
   types arrived. Only subscriptions that consume one of them are polled. Point
   `saucy.worker.head_connection` at a read replica.
3. **Polling.**
   - Owned subscriptions are polled with their normal `AllStreamSubscription::poll()`, so they keep
     the same handlers, checkpoints, poison-message handling and activity log.
   - A poll is capped at the *safe head* (below).
   - The longest-waiting subscription goes first, with at most a 2 s slice each.
   - Per-aggregate projectors are polled per stream, like `StreamTriggerJob`.

### Holes in `global_position`

`global_position` is AUTO_INCREMENT: MySQL assigns it at INSERT and makes it visible at COMMIT. A slow
transaction can keep 101 invisible while 102 is readable. A poll at that moment moves the checkpoint
past 101, and that subscription never sees it.

- **The safe head.** The head watcher only releases positions up to the first hole, and the poll
  cannot read or checkpoint past it (`BoundedAllStreamReader`, `NeverRewindCheckpointStore`).
- **The probe (MySQL).** Some holes never fill: a rollback, or a failed insert (an optimistic-lock
  conflict burns its id). A hole older than 250 ms gets one locking read on the primary,
  `SELECT … FOR SHARE NOWAIT`:
  - Error 3572: a transaction still holds the row, so the head waits (up to
    `in_flight_timeout_seconds`).
  - Rows returned: the replica is behind, so it waits.
  - Nothing returned: nobody holds those positions, so the hole is skipped right away.
- **Without the probe** (other databases, or `gap_probe` off) a hole is skipped after
  `gap_timeout_seconds`.
- **Late fills.** Skipped holes are re-checked for 15 minutes. An event that commits into one anyway
  is logged at critical level, with the subscriptions that missed it.
- **Cost to writes.** `NOWAIT` never waits behind a writer, and the shared lock lives for one
  statement only. Measured: 0 row-lock waits and unchanged commit latency.

### Locks

- The worker uses Saucy's normal locks (`RunningProcesses::start()`), held as a 30 s lease and
  renewed while busy (`RunningProcessLeases::renew()`). Queue jobs, inline `awaitProjection` runs,
  the dashboard and `saucy:replay` see a normal running process. Nothing processes a subscription
  twice, even while queue and worker both run.
- A crashed worker's expired locks are taken over 5 s after expiry (`takeOverExpired()`) instead of
  after `start()`'s 30 s grace.
- `DynamoDbRunningProcesses::stop()` now only deletes a lock its own process still holds.

### Runners: moving subscriptions one at a time

Every subscription has a **runner**, `queue` or `worker`. The default comes from
`saucy.worker.default_runner`, and runtime overrides are stored in the coordination store:

```bash
php artisan saucy:runner                         # list
php artisan saucy:runner order_projector worker  # move one to the worker
php artisan saucy:runner order_projector queue   # and back (the rollback)
php artisan saucy:runner order_projector --clear # back to the default
php artisan saucy:runner --default=worker        # flip the default
```

- **Queue side.** The process managers skip worker-run subscriptions unless the request needs them
  in sync (`awaitProjection`, `runInSync`), which keeps running inline. When nothing runs on the
  queue, no `TriggerSubscriptionsJob` is dispatched at all.
- **Caching.** Overrides are read at most once per `runner_cache_seconds` (5 s) per process, never
  per event. A failed read keeps the last known values, so a store hiccup never breaks a persist.
- **When the migration is done,** set `SAUCY_DEFAULT_RUNNER=worker` and `SAUCY_RUNNER_OVERRIDES=false`.
  Overrides are then never read, and the after-persist hook dispatches no trigger job at all.
  `saucy:runner` refuses to switch while overrides are off.
- **Hand-back grace.** After a switch to `queue`, the worker keeps running the subscription for
  `max(10 s, 2 × cache + 2 s)`, so a process that still has the old value cached cannot leave an event
  to nobody.

### Deploys

1. Before migrations, the deploy runs `php artisan saucy:worker-gate pause --sha=<commit>`.
2. Workers on any other commit finish their poll, hand their locks back and stop taking new ones.
3. The new workers (built with `SAUCY_WORKER_SHA=<commit>`) lift the pause.
4. On start a worker re-reads the last `boot_lookback_seconds`, and back to the head recorded at the
   pause. That way it sees per-aggregate triggers no running worker could act on.

### Process hygiene

Like `queue:work`:
- it restarts on `--max-time`, `--max-events` or `--memory`;
- it resets scoped instances, log context and facades after each poll;
- SIGTERM finishes the current poll, hands the locks back and exits;
- a failing subscription is backed off on its own (2 s … 15 min) while the rest keep running.

In-line retries of a failing event use `worker.retry_budget_seconds` (default 5) instead of the
queue's `poison_messages.retry_budget_seconds` (default 60).

## Setup

1. Set `SAUCY_WORKER_ENABLED=true`.
2. Pick the coordination store:
   - **SQL** (default `IlluminateWorkerCoordination`): run
     `php artisan vendor:publish --tag=saucy-worker-migrations` and migrate.
   - **DynamoDB**: bind `WorkerCoordination` to `DynamoDbWorkerCoordination` on the processes table.
3. Make sure your `RunningProcesses` binding implements `RunningProcessLeases`. Saucy's DynamoDB,
   Illuminate and Migrating implementations all do; a decorator must forward both methods.
4. Run `php artisan saucy:work --health-file=/tmp/saucy-worker.alive` in each container.
5. Move subscriptions over with `saucy:runner`.

## Metrics

`EmbeddedMetricFormatMetrics` (`SAUCY_WORKER_EMF_METRICS=true`) writes CloudWatch Embedded Metric
Format lines to stdout every 10 s, in namespace `Saucy/Worker`:
- `ProjectionLagSeconds`: autoscale and alarm on this;
- `PendingSubscriptions`, `OwnedSubscriptions`, `HeldLeases`, `EventsHandled`, `FailedPolls`;
- gap metrics: `OpenGaps`, `OldestOpenGapSeconds`, `SkippedGaps`, `GapTimeouts`, `GapProbes`,
  `LateGapFills`.

Other backends: implement `WorkerMetrics`.

## Tests

```bash
vendor/bin/phpunit workbench/tests/Worker   # SQLite; MySQL/DynamoDB tests are skipped
SAUCY_TEST_MYSQL_HOST=127.0.0.1 SAUCY_TEST_MYSQL_PASSWORD=root \
SAUCY_TEST_DYNAMODB_ENDPOINT=http://127.0.0.1:8000 vendor/bin/phpunit workbench/tests/Worker
```
