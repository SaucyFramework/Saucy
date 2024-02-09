<?php

namespace Workbench\App\BankAccount\Query;

use Illuminate\Database\Schema\Blueprint;
use Saucy\Core\Projections\AggregateProjector;
use Saucy\Core\Projections\IlluminateDatabaseProjector;
use Saucy\Core\Query\QueryHandler;
use Workbench\App\BankAccount\BankAccountAggregate;
use Workbench\App\BankAccount\Events\AccountCredited;

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

    #[QueryHandler]
    public function getBankAccountBalance(GetBankAccountBalance $query): int
    {
        $this->scopeAggregate($query->bankAccountId);
        $bankAccount = $this->find();
        if($bankAccount === null){
            return 0;
        }
        return $bankAccount['balance'];
    }


    protected function schema(Blueprint $blueprint): void
    {
        $blueprint->ulid($this->idColumnName())->primary();
        $blueprint->integer('balance');
    }
}
