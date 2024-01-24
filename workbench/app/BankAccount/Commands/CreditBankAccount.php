<?php

namespace Workbench\App\BankAccount\Commands;

use Workbench\App\BankAccount\BankAccountId;

final readonly class CreditBankAccount
{
    public function __construct(
        public BankAccountId $bankAccountId,
        public int $amount,
    )
    {
    }

    public static function withAmount(int $int, BankAccountId $bankAccountId): static
    {
        return new static($bankAccountId, $int);
    }
}
