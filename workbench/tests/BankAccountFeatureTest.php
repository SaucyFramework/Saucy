<?php

namespace Workbench\Tests;

use Saucy\Core\Command\CommandBus;
use Saucy\Core\Query\QueryBus;
use Workbench\App\BankAccount\BankAccountId;
use Workbench\App\BankAccount\Commands\CreditBankAccount;
use Workbench\App\BankAccount\Query\GetBankAccountBalance;

final class BankAccountFeatureTest extends WithDatabaseTestCase
{
    /** @test */
    public function it_handles_commands_and_queries()
    {
        $bankAccountId = BankAccountId::generate();
        $commandBus = $this->app->make(CommandBus::class);
        $commandBus->handle(CreditBankAccount::withAmount(100, $bankAccountId));
        $commandBus->handle(CreditBankAccount::withAmount(100, $bankAccountId));

        $bankAccountIdB = BankAccountId::generate();

        $commandBus->handle(CreditBankAccount::withAmount(100, $bankAccountIdB));
        $commandBus->handle(CreditBankAccount::withAmount(100, $bankAccountIdB));

        $queryBus = $this->app->make(QueryBus::class);
        $balance = $queryBus->query(GetBankAccountBalance::forId($bankAccountId));
        $this->assertEquals(100, $balance);
        $this->assertTrue(true);
    }
}
