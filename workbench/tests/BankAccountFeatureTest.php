<?php

namespace Workbench\Tests;

use Saucy\Core\Command\CommandBus;
use Saucy\Core\Query\QueryBus;
use Workbench\App\BankAccount\BankAccountId;
use Workbench\App\BankAccount\Commands\CreditBankAccount;
use Workbench\App\BankAccount\Eloquent\BankAccountModel;
use Workbench\App\BankAccount\Query\GetBankAccountBalance;
use Workbench\App\BankAccount\Query\GetTotalBankBalance;

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
        $commandBus->handle(CreditBankAccount::withAmount(50, $bankAccountIdB));

        $queryBus = $this->app->make(QueryBus::class);
        $this->assertEquals(200, $queryBus->query(GetBankAccountBalance::forId($bankAccountId)));
        $this->assertEquals(150, $queryBus->query(GetBankAccountBalance::forId($bankAccountIdB)));
        $this->assertEquals(350, $queryBus->query(new GetTotalBankBalance()));

        $this->assertEquals(2, BankAccountModel::count());
    }
}
