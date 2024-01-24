<?php

namespace Workbench\App\BankAccount\Query;

use Saucy\Core\Query\Query;
use Workbench\App\BankAccount\BankAccountId;

/** @implements Query<int> */
final readonly class GetBankAccountBalance implements Query
{
    public function __construct(public BankAccountId $bankAccountId)
    {
    }

    public static function forId(BankAccountId $bankAccountId): static
    {
        return new static($bankAccountId);
    }
}
