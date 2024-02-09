<?php

namespace Workbench\App\BankAccount\Query;

use Saucy\Core\Query\Query;

/** @implements Query<int> */
final readonly class GetTotalBankBalance implements Query
{
    public function __construct(){
    }
}
