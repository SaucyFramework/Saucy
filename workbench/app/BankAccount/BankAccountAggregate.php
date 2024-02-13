<?php

namespace Workbench\App\BankAccount;

use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootBehaviour;
use Saucy\Core\Command\CommandHandler;
use Saucy\Core\EventSourcing\Aggregate;
use Workbench\App\BankAccount\Commands\CreditBankAccount;
use Workbench\App\BankAccount\Commands\OpenBankAccount;
use Workbench\App\BankAccount\Events\AccountCredited;

#[Aggregate(BankAccountId::class, 'bank_account')]
final class BankAccountAggregate implements AggregateRoot
{
    use AggregateRootBehaviour;

    private int $balance = 0;

    #[CommandHandler]
    public static function open(OpenBankAccount $openBankAccount): self
    {
        $bankAccount = new self($openBankAccount->bankAccountId);
        $bankAccount->recordThat(new AccountCredited(0));
        return $bankAccount;
    }

    #[CommandHandler]
    public function credit(CreditBankAccount $creditBankAccount): void
    {
        $this->recordThat(new AccountCredited($creditBankAccount->amount));
    }

    private function applyAccountCredited(AccountCredited $accountCredited): void
    {
        $this->balance += $accountCredited->amount;
    }


}
