# Saucy

> Warning, this documentation is a temporary placeholder until the full documentation is ready. Find example usage in the /workbench/app directory.

### Todo: 

- [ ] Add tests & phpstan
- [ ] SubscriptionRegistryFactory -> subscriptionId: Str::of($projectorConfig->projectorClass)->snake(), change to better name, not tied to class fqn
- [x] Add Eloquent Projector
- [ ] Add reactors & process managers
- [ ] Add middleware to commands and queries (eg, check if user is authorized to execute a command or query)
- [ ] (maybe) add document store
- [ ] replay capabilities
- [ ] tracing ui
- [ ] batch commit projections

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
- All stream projectors: these projectors listen to the stream of all events. Allowing a read model that "joins" data from different aggregate roots. This comes at the costs of projection lag during high concurrency in the system.    
- AggregateProjectors: these projectors are run in isolation per aggregate root instance. For most use-cases this is sufficient, and comes with the benefit of parallel replaying (two different aggregate root's replay concurrently).

The simplest form of a projector looks like this:
```php

#[\Saucy\Core\Projections\Projector]
class MyProjection extends TypeBasedConsumer
{
    public function doSomething(AccountCredited $event) {
//        This method is called for every new AccountCredited event.   
    }
}
```

As second argument of the event handling method you could also request the MessageConsumeContext, this context contains information about the event and the replay that might be useful.

To change the AllStreamProjector to a AggregateProjector, replace the Projector attribute to the AggregateProjector attribute, and pass in the classname of the aggregate the projector should be scoped to.
```php

#[AggregateProjector(BankAccountAggregate::class)]
class MyProjection extends TypeBasedConsumer
{
    public function doSomething(AccountCredited $event) {
//        This method is called for every new AccountCredited event.   
    }
}
```

Often you'd want to persist read model state to the database. To avoid tiresome duplication, Saucy comes included with an IlluminateDatabaseProjector.
This projector scopes the projection automatically to the identifier of the aggregate root, and exposes the following methods to change state in your database:

```php
protected function upsert(array $array): void
protected function update(array $array): void
protected function increment(string $column, int $amount = 1): void
protected function create(array $array): void
protected function find(): ?array // returns null when instance could not be found
protected function delete(): void
```

Your projection should include the schema method, defining the database table schema for the projection.
The table name for the projection could be set by overriding the `tableName` method of the parent class. A default of `projection_{{ProjectionClassName}}` is used when the method is not overwritten. 

```php
protected function schema(Blueprint $blueprint): void
{
    // The id column type should be equal to the aggregateRootId type the projection is bound to. 
    // It's possible to override the `idColumnName` method in order to use a custom name
    $blueprint->ulid($this->idColumnName())->primary();
    $blueprint->integer('balance');
}
```

Full example of a projector using the IlluminateDatabaseProjector:

```php
#[AggregateProjector(BankAccountAggregate::class)]
final class BalanceProjector extends IlluminateDatabaseProjector
{
    public function ProjectAccountCredited(AccountCredited $accountCredited): void
    {
        $bankAccount = $this->find();
        if($bankAccount === null){
            $this->create(['balance' => $accountCredited->amount]);
            return;
        }

        $this->increment('balance', $accountCredited->amount);
    }
    
    // Projectors can be combined with QueryHandlers. QueryHandlers aren't magically scoped to the aggregate ID.
    // When you want to use the provided database access methods, you can first scope the projector to the right aggregate by using the scopeAggregate() method.
    // It's also possible to query the table directly using $this->queryBuilder 
    #[QueryHandler]
    public function getBankAccountBalance(GetBankAccountBalance $query): int
    {
        $this->scopeAggregate($query->bankAccountId);
        $bankAccount = $this->find();
        if($bankAccount === null){
            return 0;
        }
        return $bankAccount['balance'];
        
        // or use queryBuilder
        $bankAccount = $this->queryBuilder->where($this->idColumnName(), $query->bankAccountId->toString())->first();
    }

    protected function schema(Blueprint $blueprint): void
    {
        $blueprint->ulid($this->idColumnName())->primary();
        $blueprint->integer('balance');
    }
}
```

Next to illuminate database projectors, we also support Eloquent models as read model. 
In order to do this, we want to protect the fields we project to be updated by other pieces of code. To do this, add the

`use HasReadOnlyFields;` trait to the model you want to project to. Now we can create our Elqouent projector like this: 

```php
#[AggregateProjector(BankAccountAggregate::class)]
final class BankAccountProjector extends EloquentProjector
{
    protected static string $model = BankAccountModel::class;

    public function handleAccountCredited(AccountCredited $accountCredited): void
    {
        $bankAccount = $this->find();
        if($bankAccount === null){
            $this->create(['balance' => $accountCredited->amount]);
            return;
        }

        $this->increment('balance', $accountCredited->amount);
    }
}
```





















