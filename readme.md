# Saucy

> Warning, this documentation is a temporary placeholder until the full documentation is ready. Find example usage in the /workbench/app directory.

### Todo: 

- [ ] Add tests & phpstan
- [ ] SubscriptionRegistryFactory -> subscriptionId: Str::of($projectorConfig->projectorClass)->snake(), change to better name, not tied to class fqn
- [ ] Add Eloquent Projector
- [ ] Add reactors & process managers
- [ ] Add middleware to commands and queries (eg, check if user is authorized to execute a command or query)
- [ ] (maybe) add document store

## Inspiration / dependencies
Saucy is heavily inspired and partly uses components of EventSauce. (link) 
Next to that, the event infrastructure is inspired by Eventious (link). Ecotone was another source of inspiration for this project.

## Usage

Saucy consists mostly of 3 parts:
- CommandBus: Auto-wiring CommandBus
- QueryBys: Auto-wiring QueryBus
- Projections: Projections that simple register by adding 1 attribute.

### Command Bus

Commands can be handled by aether an event sourced aggregateRoot or a command handler. When the aggregate root is handling the command, Saucy automatically takes care of the Aggregate retrieval and persistance. 

In order to use the command bus, you can use any class as a command. Eg:
```php
final readonly class CreditBankAccount
{
    public function __construct(
        public BankAccountId $bankAccountId,
        public int $amount,
    )
    {
    }
}
```

Next, annotate the handler for the command by the `CommandHandler` annotation, and Saucy does the rest.

Outside of aggregate roots

```php
class SomeCommandHandler
{
    // Saucy automatically binds the command used as first argument to this handler method
    #[\Saucy\Core\Command\CommandHandler]
    public function handleCommand(CreditBankAccount $creditBankAccount): void {
        // do your magic here
    }
}
```

Within aggregate roots
```php
// Saucy needs this in order to know what argument in your command can be used as aggregate root ID
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

We can execute commands to the bus like this: 

```php
    // ideally inject the class in the constructor, and not use make everywhere,
    // this is just for demo purpose 
    $commandBus = $this->app->make(\Saucy\Core\Command\CommandBus::class);
    $commandBus->handle($command);
```

### Query Bus
The Query Bus can be used to query the domain for information. It uses similar principles as the command bus. The main difference is that Query's can return something.

Defining a query:

```php
/** @implements Query<int> */
final readonly class GetBankAccountBalance implements Query
{
    public function __construct(
    public BankAccountId $bankAccountId
    ){
    }
}
```

Within the query doc-bloc we can hint the return type expected (in this case int, but it can be any class).

To handle a query, annotate the method responsible for handling with `QueryHandler`. Similar as with the command bus, the first argument is the Query the handler method is bound to. 

```php

class SomeQueryHandler
{
    #[\Saucy\Core\Query\QueryHandler]
    public function getGetBankAccountBalance(GetBankAccountBalance $getBankAccountBalance): int {
        return $this->repository->getBalanceFor($getBankAccountBalance->bankAccountId);
    }
}
```

We can execute commands to the bus like this:

```php
    // ideally inject the class in the constructor, and not use make everywhere,
    // this is just for demo purpose 
    $queryBus = $this->app->make(\Saucy\Core\Query\QueryBus::class);
    $result = $queryBus->query($command);
```

A nice pattern to use, is to locate queryHandlers that respond with data from a specific projector inside that projector as well. All logic for answering the query can than be found in one place. For an example, see the section about projectors.

## Projectors

Projectors can be used to map events into read models dedicated for querying information.

We can identify two different type of projectors: 
- AggregateProjectors: these projectors are run in isolation per aggregate root instance. ...
- All stream projectors: these projectors listen to all events in the 
