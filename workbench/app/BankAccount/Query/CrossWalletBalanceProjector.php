<?php

namespace Workbench\App\BankAccount\Query;

use Saucy\Core\Projections\Projector;
use Saucy\Core\Query\QueryHandler;
use Saucy\Core\Subscriptions\Consumers\TypeBasedConsumer;
use Workbench\App\BankAccount\Events\AccountCredited;

#[Projector]
final class CrossWalletBalanceProjector extends TypeBasedConsumer
{
    public int $balance = 0;
    public function handleAccountCredited(AccountCredited $accountCredited): void
    {
        $this->balance += $accountCredited->amount;
    }

    #[QueryHandler]
    public function getBalance(GetTotalBankBalance $getTotalBankBalance): int
    {
        return $this->balance;
    }


}
