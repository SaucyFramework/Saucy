<?php

namespace Workbench\App\BankAccount\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Saucy\Core\Projections\Eloquent\HasReadOnlyFields;

final class BankAccountModel extends Model
{
    use HasReadOnlyFields;

    protected $guarded = [];

    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

}
