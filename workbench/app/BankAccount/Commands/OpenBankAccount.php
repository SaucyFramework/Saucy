<?php

namespace Workbench\App\BankAccount\Commands;

use Workbench\App\BankAccount\BankAccountId;

final readonly class OpenBankAccount
{
    public function __construct(
        public BankAccountId $bankAccountId,
    )
    {
    }
}
