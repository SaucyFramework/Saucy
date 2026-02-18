# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Saucy is a Laravel package providing Event Sourcing and CQRS (Command Query Responsibility Segregation) built on top of EventSauce. It uses attribute-based auto-discovery for handlers, projectors, and aggregates.

## Commands

### Tests
```bash
vendor/bin/phpunit                                    # Run all tests
vendor/bin/phpunit workbench/tests/BankAccountFeatureTest.php  # Run a single test file
vendor/bin/phpunit --filter it_handles_commands        # Run a single test by name
```
Tests use Orchestra Testbench with `DB_CONNECTION=testing` (SQLite in-memory). Workbench tests extend `WithDatabaseTestCase` which handles migrations and service provider registration.

### Static Analysis & Formatting
```bash
vendor/bin/phpstan analyse          # PHPStan level 9 (strictest)
vendor/bin/php-cs-fixer fix         # Fix code style (@PER-CS ruleset)
vendor/bin/php-cs-fixer fix --dry-run  # Check code style without fixing
```

### Development Server
```bash
composer serve                      # Build workbench and start dev server
```

## Architecture

### Module Structure

The project is split into four composer-autoloaded modules:

| Module | Namespace | Path | Purpose |
|--------|-----------|------|---------|
| **Core** | `Saucy\Core` | `core/src/` | CQRS buses, event sourcing, projections, subscriptions, framework integration |
| **MessageStorage** | `Saucy\MessageStorage` | `messageStorage/src/` | Event store (Illuminate DB), serialization, hooks |
| **Ids** | `Saucy\Ids` | `ids/src/` | ULID-based aggregate root ID base class |
| **Tasks** | `Saucy\Tasks` | `tasks/src/` | Generic task runner with container-based DI |

The `workbench/` directory contains an example BankAccount domain used for integration testing.

### CQRS Flow

- **CommandBus** (`CommandBus` → `TaskMapCommandHandler` → resolved handler): Commands are routed by their class type to methods/classes annotated with `#[CommandHandler]`.
- **QueryBus** (`QueryBus` → `SelfHandlingQueryHandler` → `QueryHandlingMiddleware`): Queries implement `Query<TResult>` and are routed to `#[QueryHandler]` annotated methods.
- Both buses use a middleware chain pattern.

### Event Sourcing

- Aggregates are marked with `#[Aggregate(aggregateIdClass: ..., 'stream_type')]` and extend EventSauce's `AggregateRoot`.
- `AggregateStore` persists/retrieves aggregates. `EventSourcingCommandHandler` provides optimistic concurrency with backoff/retry.
- Events are stored in the `event_store` table via `IlluminateMessageStorage`.

### Projections

Two types of projectors, both extending `TypeBasedConsumer` which auto-routes events to `handle{EventName}()` methods:

- **`#[Projector]`**: Subscribes to the all-stream (cross-aggregate). Uses `AllStreamSubscription`.
- **`#[AggregateProjector(AggregateClass::class)]`**: Scoped to a single aggregate's stream. Uses `StreamSubscription`.

Both support `async: true|false` parameter. Base classes available: `IlluminateDatabaseProjector` (auto-creates tables) and `EloquentProjector` (projects to Eloquent models with `HasReadOnlyFields` trait).

### Auto-Discovery

`BuildSaucyProjectMappings` scans directories configured in `config('saucy.directories')` using `league/construct-finder` and `robertbaelde/attribute-finder`. It builds and caches maps for commands, queries, projectors, aggregates, and the type map. Cache is rebuilt automatically in local env; use `php artisan saucy:build-cache` in production.

### Subscriptions & Event Processing

Subscriptions are poll-based with checkpoint tracking. After events are persisted, `HooksMessageStore` triggers hooks: `TriggerSubscriptionProcessesAfterPersist` (async) and `PlaySynchronousProjectorsAfterPersist` (sync projectors). In testing env (`app.env === 'testing'`), all subscriptions run synchronously.

## Conventions

- **Commands**: Imperative names (`CreditBankAccount`)
- **Events**: Past tense (`AccountCredited`)
- **Queries**: Question form (`GetBankAccountBalance`), implement `Query<TResult>`
- **Handler methods**: Named after event type (`handleAccountCredited()`)
- **IDs**: Extend `Saucy\Ids\Ulid` abstract class
- **Code style**: @PER-CS (enforced by php-cs-fixer)
- **PHP**: 8.2+ with strict types
