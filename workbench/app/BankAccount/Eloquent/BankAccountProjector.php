<?php

namespace Workbench\App\BankAccount\Eloquent;

use Saucy\Core\Projections\AggregateProjector;
use Saucy\Core\Projections\Eloquent\EloquentProjector;
use Workbench\App\BankAccount\BankAccountAggregate;
use Workbench\App\BankAccount\Events\AccountCredited;

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
